<?php

namespace HeistaAddressCheck\Crons;

use HeistaAddressCheck\Models\PendingAddressCheck;
use HeistaAddressCheck\PlatformEnvironment;
use HeistaAddressCheck\Repositories\PendingAddressCheckRepository;
use HeistaAddressCheck\Services\AddressCheckApplyService;
use HeistaAddressCheck\Services\SaasClient;
use Plenty\Modules\Cron\Contracts\CronHandler;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Throwable;

/**
 * Fallback when the inbound webhook from the SaaS does not arrive (firewall, retry budget exhausted, etc.).
 *
 * Plenty's most frequent built-in cron slot is every 5 minutes. The webhook is the fast path;
 * this cron is the safety net so no order silently stalls.
 */
class FallbackPollCron extends CronHandler
{
    use Loggable;

    private const GRACE_SECONDS = 90;
    private const BATCH_LIMIT   = 50;

    private PendingAddressCheckRepository $pendingRepo;
    private SaasClient $saasClient;
    private AddressCheckApplyService $apply;
    private ConfigRepository $config;

    public function __construct(
        PendingAddressCheckRepository $pendingRepo,
        SaasClient $saasClient,
        AddressCheckApplyService $apply,
        ConfigRepository $config
    ) {
        $this->pendingRepo = $pendingRepo;
        $this->saasClient  = $saasClient;
        $this->apply       = $apply;
        $this->config      = $config;
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
            // Other states (PENDING, PROCESSING, AWAITING_EXTERNAL): leave untouched, retry next tick.
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
