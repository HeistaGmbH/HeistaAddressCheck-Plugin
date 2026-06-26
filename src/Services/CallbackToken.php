<?php

namespace HeistaAddressCheck\Services;

/**
 * Self-authenticating callback token, derived from the merchant's API key.
 *
 * The merchant configures only the API key — there is no separate callback
 * secret. On submit, the plugin issues a per-job token and sends it to the
 * platform as `callbackSecret`; the platform echoes it verbatim in the
 * `X-Heista-Secret` header on the result webhook, where `verify()` re-derives
 * and checks it. Only a holder of the API key can produce a valid token, so a
 * forged callback is rejected. The token is per-job (fresh nonce each time), so
 * a leaked token can replay at most one job's callback — never all of them.
 *
 * Format:  token = nonce . '.' . hmac
 *   nonce = sha256 hex over per-call entropy (unique, need not be secret)
 *   hmac  = hash_hmac('sha256', nonce, apiKey)
 *
 * Whitelist note: `hash`, `hash_hmac`, `random_int` are allowed; `random_bytes`
 * and `hash_equals` are not — hence sha256-of-random_int for the nonce and a
 * plain `===` compare (both sides are fixed-length sha256 hex, so the timing
 * leak is negligible over the network).
 */
class CallbackToken
{
    public static function issue(string $apiKey): string
    {
        $entropy = random_int(0, PHP_INT_MAX) . '|' . random_int(0, PHP_INT_MAX) . '|' . time();
        $nonce   = hash('sha256', $entropy);
        $sig     = hash_hmac('sha256', $nonce, $apiKey);

        return $nonce . '.' . $sig;
    }

    public static function verify(string $token, string $apiKey): bool
    {
        if ($apiKey === '' || $token === '') {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        $nonce = $parts[0];
        $sig   = $parts[1];
        if ($nonce === '' || $sig === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $nonce, $apiKey);

        return $sig === $expected;
    }
}
