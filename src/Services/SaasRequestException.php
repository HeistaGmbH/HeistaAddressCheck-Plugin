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
 * `platformAnswered` (1.9.0) says whether the body came from the platform's own
 * error handler. A proxy, CDN or WAF in front of it answers with a status code
 * too, and that answer is not a decision of the platform (SaasClient::platformAnswered).
 * `jobId` (1.9.0) is set when the job was created and only its confirm failed,
 * so a retry can confirm that job instead of creating a second one.
 */
class SaasRequestException extends RuntimeException
{
    private int $httpStatus;
    private int $retryAfterSeconds;
    private string $errorCode;
    private bool $platformAnswered;
    private string $jobId = '';

    public function __construct(
        string $message,
        int $httpStatus = 0,
        int $retryAfterSeconds = 0,
        string $errorCode = '',
        bool $platformAnswered = false
    ) {
        parent::__construct($message);
        $this->httpStatus        = $httpStatus;
        $this->retryAfterSeconds = $retryAfterSeconds;
        $this->errorCode         = $errorCode;
        $this->platformAnswered  = $platformAnswered;
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

    public function platformAnswered(): bool
    {
        return $this->platformAnswered;
    }

    /**
     * Records the job this failure belongs to. Mutates and returns $this, because
     * an exception cannot be cloned.
     */
    public function withJobId(string $jobId): self
    {
        $this->jobId = $jobId;
        return $this;
    }

    /** The created job whose confirm failed, '' when the create itself failed. */
    public function getJobId(): string
    {
        return $this->jobId;
    }

    /**
     * True when a later attempt can succeed without anyone changing anything:
     * the platform was not reached, it asked us to wait (429, 408), it failed on
     * its side (5xx), or something in front of it answered instead (1.9.0: a 4xx
     * without the platform's error body, such as the proxy's "404 page not found"
     * of 2026-11-03). Every other 4xx is a decision, not a hiccup: a revoked key,
     * an empty wallet or invalid DHL credentials stay that way until a person
     * acts, and retrying them only hides the problem for a day.
     */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 0
            || $this->httpStatus === 408
            || $this->httpStatus === 429
            || $this->httpStatus >= 500
            || ($this->httpStatus >= 400 && !$this->platformAnswered);
    }

    /**
     * True when the platform as a whole is out of reach or asked us to slow down, so
     * the next request in the same cron run would fail the same way: no connection or a
     * timeout, an answer from something in front of the platform, or a 429. A 5xx in
     * the platform's own envelope concerns one job and is not counted.
     */
    public function platformUnavailable(): bool
    {
        return $this->httpStatus === 429
            || ($this->isRetryable() && !$this->platformAnswered);
    }
}
