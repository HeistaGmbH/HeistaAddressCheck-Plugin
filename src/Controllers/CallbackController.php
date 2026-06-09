<?php

namespace HeistaAddressCheck\Controllers;

use HeistaAddressCheck\Services\AddressCheckApplyService;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Throwable;

class CallbackController extends Controller
{
    use Loggable;

    public function receive(
        Request $request,
        Response $response,
        ConfigRepository $config,
        AddressCheckApplyService $apply
    ) {
        // Plenty's Apache+FPM hosting strips the Authorization header before it
        // reaches plugin code. Use the X-Heista-Secret custom header instead;
        // fall back to Authorization: Bearer for setups that do forward it.
        $customHeader = (string) $request->header('X-Heista-Secret', '');
        $authHeader   = (string) $request->header('Authorization', '');
        $remoteIp     = (string) $request->header('X-Forwarded-For', $request->header('X-Real-Ip', ''));

        $providedSecret = '';
        $secretSource   = 'none';
        if ($customHeader !== '') {
            $providedSecret = trim($customHeader);
            $secretSource   = 'x-heista-secret';
        } elseif (str_starts_with($authHeader, 'Bearer ')) {
            $providedSecret = trim(substr($authHeader, 7));
            $secretSource   = 'authorization';
        }

        $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.callbackReceived', [
            'remoteIp'     => $remoteIp,
            'secretSource' => $secretSource,
        ]);

        $expectedSecret = trim((string) $config->get('HeistaAddressCheck.callbackSecret'));
        if ($expectedSecret === '') {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.callbackNoSecret');
            return $response->json(['error' => 'callback secret not configured'], 401);
        }

        if ($providedSecret !== $expectedSecret) {
            $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.callbackUnauthorized', [
                'remoteIp'     => $remoteIp,
                'secretSource' => $secretSource,
                'expectedLen'  => strlen($expectedSecret),
                'providedLen'  => strlen($providedSecret),
                'expectedSha1' => substr(sha1($expectedSecret), 0, 8),
                'providedSha1' => substr(sha1($providedSecret), 0, 8),
            ]);
            return $response->json(['error' => 'invalid secret'], 401);
        }

        $body = $request->all();
        if (!is_array($body)) {
            $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.callbackInvalidBody');
            return $response->json(['error' => 'invalid body'], 400);
        }

        $jobId = (string) ($body['jobId'] ?? '');
        if ($jobId === '') {
            $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.callbackMissingJobId');
            return $response->json(['error' => 'missing jobId'], 400);
        }

        $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.callbackAccepted', [
            'jobId'  => $jobId,
            'status' => (string) ($body['status'] ?? ''),
        ]);

        try {
            $apply->apply($jobId, $body);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.callbackApplyFailed', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);
            // Still return 200 so the platform doesn't retry forever — the cron will catch it.
        }

        return $response->json(['received' => true]);
    }

}
