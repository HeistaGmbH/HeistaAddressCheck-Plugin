<?php

namespace HeistaAddressCheck\Crons;

use HeistaAddressCheck\Models\PendingAddressCheck;
use HeistaAddressCheck\PlatformEnvironment;
use HeistaAddressCheck\Repositories\PendingAddressCheckRepository;
use HeistaAddressCheck\Services\AddressCheckApplyService;
use HeistaAddressCheck\Services\AddressCheckSubmitService;
use HeistaAddressCheck\Services\SaasClient;
use HeistaAddressCheck\Services\SaasRequestException;
use Plenty\Modules\Cron\Contracts\CronHandler;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Log\Reportable;
use Throwable;

/**
 * Two jobs on Plenty's 5-minute cron slot. First, poll for completed jobs when
 * the webhook callback never arrives (firewall, exhausted retries, etc.), the
 * backup to the webhook fast path. Second, since 1.8.0, resubmit the orders
 * whose submit never reached the platform, so an outage on our side is a delay
 * for the merchant and not a queue of orders in the error status.
 */
class FallbackPollCron extends CronHandler
{
    use Loggable;
    use Reportable;

    private const GRACE_SECONDS  = 90;
    private const BATCH_LIMIT    = 50;
    /**
     * RETRY rows resubmitted per tick. Two requests each, so about a minute of
     * work, and well inside the platform's limit of 300 requests a minute per
     * route: a backlog of a few thousand orders drains in a couple of hours
     * without a single refusal.
     */
    private const RESUBMIT_LIMIT = 100;

    private PendingAddressCheckRepository $pendingRepo;
    private SaasClient $saasClient;
    private AddressCheckApplyService $apply;
    private AddressCheckSubmitService $submitService;
    private ConfigRepository $config;

    public function __construct(
        PendingAddressCheckRepository $pendingRepo,
        SaasClient $saasClient,
        AddressCheckApplyService $apply,
        AddressCheckSubmitService $submitService,
        ConfigRepository $config
    ) {
        $this->pendingRepo   = $pendingRepo;
        $this->saasClient    = $saasClient;
        $this->apply         = $apply;
        $this->submitService = $submitService;
        $this->config        = $config;
    }

    public function handle(): void
    {
        $apiKey = trim((string) $this->config->get('HeistaAddressCheck.apiKey'));
        if ($apiKey === '') {
            return;
        }
        $environment = (string) $this->config->get('HeistaAddressCheck.environment', PlatformEnvironment::PRODUCTION);
        $devOverride = trim((string) $this->config->get('HeistaAddressCheck.devApiBaseUrlOverride'));
        $apiBaseUrl  = PlatformEnvironment::baseUrlFor($environment, $devOverride);

        // Once a request finds the platform out of reach, the rest of the run sends
        // nothing (1.9.0). A platform that accepts connections and never answers costs
        // 15 s per request, and 50 polls plus 100 resubmits would hold one run for over
        // half an hour, far past the five-minute slot. Queued rows still go through
        // resubmit() with that failure, so a row whose waiting time has run out is
        // closed on time; the others keep their schedule for the next run.
        $platformDown = $this->pollStale($apiBaseUrl, $apiKey);
        $this->resubmitDue($platformDown);
    }

    /**
     * Orders queued because the platform could not be reached. The submit
     * service decides per row whether to try, wait longer or give up; this loop
     * only guarantees that one bad row cannot stop the others, and carries an
     * unreachable platform from one row to the next.
     */
    private function resubmitDue(?SaasRequestException $platformDown): void
    {
        foreach ($this->pendingRepo->findRetryDue(self::RESUBMIT_LIMIT) as $row) {
            try {
                $seen = $this->submitService->resubmit($row, $platformDown);
            } catch (Throwable $e) {
                $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.cronResubmitFailed', [
                    'orderId' => (int) $row->orderId,
                    'error'   => $e->getMessage(),
                ]);
                continue;
            }

            if ($platformDown === null && $seen !== null) {
                $platformDown = $seen;
                $this->reportPlatformUnavailable('resubmit', $seen);
            }
        }
    }

    /**
     * Returns the failure when a poll found the platform out of reach, and polls no
     * further in this run; null otherwise. A poll that fails for one job (a 404 for a
     * purged one, say) does not stop the others.
     */
    private function pollStale(string $apiBaseUrl, string $apiKey): ?SaasRequestException
    {
        $rows = $this->pendingRepo->findStalePending(self::GRACE_SECONDS, self::BATCH_LIMIT);
        if (empty($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            $jobId = (string) $row->jobId;
            if ($jobId === '') {
                continue;
            }

            try {
                $body = $this->saasClient->pollJob($apiBaseUrl, $apiKey, $jobId);
            } catch (Throwable $e) {
                $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.cronPollFailed', [
                    'jobId' => $jobId,
                    'error' => $e->getMessage(),
                ]);
                if ($e instanceof SaasRequestException && $e->platformUnavailable()) {
                    $this->reportPlatformUnavailable('poll', $e);
                    return $e;
                }
                continue;
            }

            $status = (string) ($body['status'] ?? '');
            // FAILED goes through apply() as well, which writes the note and sets the
            // error status the way the callback path does. Until 1.9.0 the poll only
            // closed the row, so an order whose result came by poll stayed in its
            // trigger status with no word on it. Since the confirm retry, a job whose
            // start failed on the platform reaches the plugin by this route.
            if ($status === 'COMPLETED' || $status === 'FAILED') {
                $this->safeApply($jobId, $body);
            }
            // Anything else (PENDING/PROCESSING/AWAITING_EXTERNAL): retry next tick.
        }

        return null;
    }

    /**
     * One line per run that met an unreachable platform, as report() so it shows
     * without the merchant switching the plugin log on.
     */
    private function reportPlatformUnavailable(string $phase, SaasRequestException $e): void
    {
        $this->report(__METHOD__, 'HeistaAddressCheck::log.cronPlatformUnavailable', [
            'phase' => $phase,
            'error' => $e->getMessage(),
        ]);
    }

    private function safeApply(string $jobId, array $body): void
    {
        try {
            $this->apply->apply($jobId, $body);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.cronApplyFailed', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
