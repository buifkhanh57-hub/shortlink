<?php

declare(strict_types=1);

namespace Shortlink\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for every domain-specific failure inside Shortlink.
 *
 * The exception carries an HTTP status code and a machine readable error
 * code, so the front controller can translate any thrown exception into a
 * consistent JSON error payload without duplicating mapping logic in every
 * controller.
 */
class ShortlinkException extends RuntimeException
{
    /** Default HTTP status the front controller should respond with. */
    protected int $statusCode = 500;

    /** Machine readable error code exposed in API error payloads. */
    protected string $errorCode = 'internal_error';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?int $statusCode = null
    ) {
        parent::__construct($message, $code, $previous);
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
    }

    /** HTTP status code the front controller should use for this failure. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Machine readable error code, e.g. "not_found" or "rate_limited". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Build the JSON payload used for API error responses.
     *
     * @return array{error: array{code: string, message: string}}
     */
    public function toPayload(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ];
    }
}
