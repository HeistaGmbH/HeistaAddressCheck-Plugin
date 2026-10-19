<?php

namespace HeistaAddressCheck\Services;

use RuntimeException;

/**
 * A request to the Heista platform that did not succeed, with enough on it for
 * the caller to decide whether trying again later can help.
 *
 * `httpStatus` is 0 when the platform was never reached (DNS, connect, timeout).
 * `retryAfterSeconds` is what the platform asked for on a 429 (its Retry-After
 * header, or error.details.retryAfterSeconds in the body), 0 when it said nothing.
 * `errorCode` is the platform's error.code (UNAUTHORIZED, RATE_LIMITED, ...),
 * '' when the body carried none.
 */
class SaasRequestException extends RuntimeException
{
    private int $httpStatus;
    private int $retryAfterSeconds;
    private string $errorCode;

    public function __construct(string $message, int $httpStatus = 0, int $retryAfterSeconds = 0, string $errorCode = '')
    {
        parent::__construct($message);
        $this->httpStatus        = $httpStatus;
        $this->retryAfterSeconds = $retryAfterSeconds;
        $this->errorCode         = $errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * True when a later attempt can succeed without anyone changing anything:
     * the platform was not reached, it asked us to wait (429, 408), or it failed
     * on its side (5xx). Every other 4xx is a decision, not a hiccup: a revoked
     * key, an empty wallet or invalid DHL credentials stay that way until a
     * person acts, and retrying them only hides the problem for a day.
     */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 0
            || $this->httpStatus === 408
            || $this->httpStatus === 429
            || $this->httpStatus >= 500;
    }
}
