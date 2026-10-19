<?php

namespace HeistaAddressCheck\Crons;

use HeistaAddressCheck\Models\PendingAddressCheck;
use HeistaAddressCheck\PlatformEnvironment;
use HeistaAddressCheck\Repositories\PendingAddressCheckRepository;
use HeistaAddressCheck\Services\AddressCheckApplyService;
use HeistaAddressCheck\Services\AddressCheckSubmitService;
use HeistaAddressCheck\Services\SaasClient;
use Plenty\Modules\Cron\Contracts\CronHandler;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
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

        $this->pollStale($apiBaseUrl, $apiKey);
        $this->resubmitDue();
    }

    /**
     * Orders queued because the platform could not be reached. The submit
     * service decides per row whether to try, wait longer or give up; this loop
     * only guarantees that one bad row cannot stop the others.
     */
    private function resubmitDue(): void
    {
        foreach ($this->pendingRepo->findRetryDue(self::RESUBMIT_LIMIT) as $row) {
            try {
                $this->submitService->resubmit($row);
            } catch (Throwable $e) {
                $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.cronResubmitFailed', [
                    'orderId' => (int) $row->orderId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    private function pollStale(string $apiBaseUrl, string $apiKey): void
    {
        $rows = $this->pendingRepo->findStalePending(self::GRACE_SECONDS, self::BATCH_LIMIT);
        if (empty($rows)) {
            return;
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
                continue;
            }

            $status = (string) ($body['status'] ?? '');
            if ($status === 'COMPLETED') {
                $this->safeApply($jobId, $body);
            } elseif ($status === 'FAILED') {
                $this->pendingRepo->markFailed($row, 'SaaS job FAILED');
            }
            // Anything else (PENDING/PROCESSING/AWAITING_EXTERNAL): retry next tick.
        }
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
