<?php

declare(strict_types=1);

namespace Shortlink\Exception;

/**
 * Thrown when a resource cannot be created because of a collision,
 * e.g. a custom alias that is already taken. Mapped to HTTP 409.
 */
final class ConflictException extends ShortlinkException
{
    protected int $statusCode = 409;

    protected string $errorCode = 'conflict';

    public function __construct(string $message = 'The resource conflicts with an existing one.')
    {
        parent::__construct($message);
    }
}
