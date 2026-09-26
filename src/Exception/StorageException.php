<?php

declare(strict_types=1);

namespace Shortlink\Exception;

use Throwable;

/**
 * Thrown when the JSON file storage layer fails: unreadable files,
 * corrupted documents, lock timeouts or a non-writable data directory.
 *
 * This is a server-side failure and is always mapped to HTTP 500.
 */
final class StorageException extends ShortlinkException
{
    protected int $statusCode = 500;

    protected string $errorCode = 'storage_error';

    public function __construct(string $message = 'Storage backend failure.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
