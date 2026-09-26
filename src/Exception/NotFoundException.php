<?php

declare(strict_types=1);

namespace Shortlink\Exception;

/**
 * Thrown when a short link (or any other resource) does not exist.
 * Mapped to HTTP 404 by the front controller.
 */
final class NotFoundException extends ShortlinkException
{
    protected int $statusCode = 404;

    protected string $errorCode = 'not_found';

    public function __construct(string $message = 'The requested resource does not exist.')
    {
        parent::__construct($message);
    }
}
