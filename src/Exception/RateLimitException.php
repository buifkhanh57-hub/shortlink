<?php

declare(strict_types=1);

namespace Shortlink\Exception;

/**
 * Thrown when a client exceeds a rate limit window.
 * Mapped to HTTP 429 and paired with a Retry-After hint in seconds.
 */
final class RateLimitException extends ShortlinkException
{
    protected int $statusCode = 429;

    protected string $errorCode = 'rate_limited';

    /** Seconds until the caller may retry. */
    private int $retryAfter;

    public function __construct(string $message = 'Too many requests.', int $retryAfter = 60)
    {
        parent::__construct($message);
        $this->retryAfter = max(0, $retryAfter);
    }

    /** Seconds the client should wait before retrying. */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function toPayload(): array
    {
        $payload = parent::toPayload();
        $payload['error']['retry_after'] = $this->retryAfter;

        return $payload;
    }
}
