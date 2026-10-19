<?php

namespace HeistaAddressCheck\Services;

use Plenty\Plugin\Log\Loggable;
use RuntimeException;

/**
 * cURL client for the Heista V1 API.
 *
 * Uses curl and json functions directly instead of LibraryCallContract plus a
 * Guzzle connector; that route gave us opaque failures that were hard to debug.
 */
class SaasClient
{
    use Loggable;

    /**
     * Client identity reported to the SaaS on every call (X-Heista-Client /
     * X-Heista-Client-Version). Merchants update the plugin on their own schedule, so this
     * is the platform's ONLY way to see which builds are still live before changing the
     * plugin-facing contract; the dashboard rolls it up per tenant.
     *
     * MUST be kept in step with "version" in plugin.json — bump both in the same commit.
     * There is no runtime API to read the manifest version, so this constant is the source
     * of truth for what we report.
     */
    const CLIENT_NAME    = 'HeistaAddressCheck';
    const CLIENT_VERSION = '1.8.0';

    public function submitJob(string $baseUrl, string $apiKey, array $body, int $connectTimeoutMs = 5000): string
    {
        $created = $this->request('POST', rtrim($baseUrl, '/') . '/api/v1/jobs', $apiKey, $body, $connectTimeoutMs);

        $jobId = $created['jobId'] ?? null;
        if (!is_string($jobId) || $jobId === '') {
            throw new RuntimeException('SaaS submit response missing jobId');
        }

        $confirmUrl = rtrim($baseUrl, '/') . '/api/v1/jobs/' . rawurlencode($jobId) . '/confirm';
        $this->request('POST', $confirmUrl, $apiKey, null, $connectTimeoutMs);

        return $jobId;
    }

    public function pollJob(string $baseUrl, string $apiKey, string $jobId, int $connectTimeoutMs = 5000): array
    {
        $url = rtrim($baseUrl, '/') . '/api/v1/jobs/' . rawurlencode($jobId);
        return $this->request('GET', $url, $apiKey, null, $connectTimeoutMs);
    }

    /**
     * @return array<string, mixed> Decoded JSON body (empty array if response had no body).
     *
     * @throws SaasRequestException on a transport error or any status >= 400, carrying the
     *                              status, the platform's error code and its Retry-After, so
     *                              the submit path can tell "try again later" from "refused".
     */
    private function request(string $method, string $url, string $apiKey, ?array $body, int $connectTimeoutMs): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'X-Heista-Client: ' . self::CLIENT_NAME,
            'X-Heista-Client-Version: ' . self::CLIENT_VERSION,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT           => 15,
            CURLOPT_FOLLOWLOCATION    => false,
            // Response headers come back in front of the body, so a 429's
            // Retry-After can be read without a header callback.
            CURLOPT_HEADER            => true,
        ]);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload   = json_encode($body);
            if ($payload === false) {
                curl_close($ch);
                throw new RuntimeException('Failed to JSON-encode SaaS request body');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw        = curl_exec($ch);
        $errno      = curl_errno($ch);
        $errmsg     = curl_error($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new SaasRequestException(sprintf('cURL %s %s failed: [%d] %s', $method, $url, $errno, $errmsg), 0);
        }

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $rawBody    = (string) substr((string) $raw, $headerSize);

        if ($status >= 400) {
            $decoded = json_decode($rawBody, true);
            $decoded = is_array($decoded) ? $decoded : [];
            throw new SaasRequestException(
                sprintf('HTTP %s %s returned %d: %s', $method, $url, $status, substr($rawBody, 0, 500)),
                $status,
                self::retryAfterSeconds($rawHeaders, $decoded),
                (string) ($decoded['error']['code'] ?? '')
            );
        }

        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new SaasRequestException('Invalid JSON response: ' . substr($rawBody, 0, 200), $status);
        }

        return $decoded;
    }

    /**
     * Seconds the platform asked us to wait: its Retry-After header (always seconds,
     * never a date, since 2026-10-19), else error.details.retryAfterSeconds from the
     * body, else 0. Header names arrive lowercased over HTTP/2, hence the /i.
     */
    public static function retryAfterSeconds(string $rawHeaders, array $decodedBody): int
    {
        if (preg_match('/^Retry-After:\s*(\d+)\s*$/mi', $rawHeaders, $match) === 1) {
            return (int) $match[1];
        }

        $fromBody = $decodedBody['error']['details']['retryAfterSeconds'] ?? null;

        return is_numeric($fromBody) ? (int) $fromBody : 0;
    }
}
