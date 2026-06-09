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
     */
    private function request(string $method, string $url, string $apiKey, ?array $body, int $connectTimeoutMs): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT           => 15,
            CURLOPT_FOLLOWLOCATION    => false,
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

        $raw     = curl_exec($ch);
        $errno   = curl_errno($ch);
        $errmsg  = curl_error($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new RuntimeException(sprintf('cURL %s %s failed: [%d] %s', $method, $url, $errno, $errmsg));
        }

        if ($status >= 400) {
            throw new RuntimeException(sprintf(
                'HTTP %s %s returned %d: %s',
                $method,
                $url,
                $status,
                substr((string) $raw, 0, 500)
            ));
        }

        if ($raw === '' || $raw === null) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response: ' . substr((string) $raw, 0, 200));
        }

        return $decoded;
    }
}
