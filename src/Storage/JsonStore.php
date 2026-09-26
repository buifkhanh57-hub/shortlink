<?php

declare(strict_types=1);

namespace Shortlink\Storage;

use Shortlink\Exception\StorageException;

/**
 * File-backed JSON document store with advisory locking and atomic writes.
 *
 * Every mutation follows the same safe sequence:
 *
 *  1. acquire an exclusive lock on the dedicated "<file>.lock" sidecar,
 *  2. read the current document from disk,
 *  3. let the caller transform it (mutate()) or replace it (write()),
 *  4. serialize to "<file>.tmp" and rename() it over the real path,
 *  5. release the lock.
 *
 * rename(2) is atomic on POSIX filesystems, so concurrent readers never
 * observe a half-written document; the flock guarantees that concurrent
 * read-modify-write cycles are fully serialized across FPM workers and
 * CLI processes.
 */
final class JsonStore
{
    private string $path;

    private string $lockPath;

    private string $tmpPath;

    private bool $pretty;

    private int $lockRetries;

    private int $retryDelayMicros;

    public function __construct(
        string $path,
        bool $pretty = true,
        int $lockRetries = 20,
        int $retryDelayMicros = 50000
    ) {
        if (trim($path) === '') {
            throw new StorageException('Storage path must not be empty.');
        }
        $this->path = $path;
        $this->lockPath = $path . '.lock';
        $this->tmpPath = $path . '.tmp';
        $this->pretty = $pretty;
        $this->lockRetries = max(1, $lockRetries);
        $this->retryDelayMicros = max(0, $retryDelayMicros);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function size(): int
    {
        $size = @filesize($this->path);

        return $size === false ? 0 : (int)$size;
    }

    public function lastModified(): int
    {
        $mtime = @filemtime($this->path);

        return $mtime === false ? 0 : (int)$mtime;
    }

    /**
     * Read the whole document. A missing file reads as an empty array;
     * a corrupted file raises a StorageException instead of failing later.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new StorageException(sprintf('Unable to read "%s".', $this->path));
        }
        if (trim($raw) === '') {
            return [];
        }

        return self::decode($raw, $this->path);
    }

    /**
     * Replace the whole document atomically.
     *
     * @param array<string, mixed> $data
     */
    public function write(array $data): void
    {
        $this->ensureDirectory();
        $handle = $this->acquireLock();
        try {
            $this->writeUnderLock($data);
        } finally {
            $this->releaseLock($handle);
        }
    }

    /**
     * Run a read-modify-write cycle under the exclusive lock. The callback
     * receives the decoded document and returns the new document; whatever
     * the callback returns is also returned from mutate(). If the callback
     * returns a non-array (e.g. a scalar result of a lookup) nothing is
     * written, which makes the method usable for locked reads as well.
     *
     * @param callable(array<string, mixed>): mixed $fn
     * @return mixed
     */
    public function mutate(callable $fn): mixed
    {
        $this->ensureDirectory();
        $handle = $this->acquireLock();
        try {
            $data = [];
            if ($this->exists()) {
                $raw = @file_get_contents($this->path);
                if ($raw === false) {
                    throw new StorageException(sprintf('Unable to read "%s".', $this->path));
                }
                $data = trim($raw) === '' ? [] : self::decode($raw, $this->path);
            }
            $result = $fn($data);
            if (is_array($result)) {
                $this->writeUnderLock($result);
            }

            return $result;
        } finally {
            $this->releaseLock($handle);
        }
    }

    /**
     * Remove the document (and any leftover temp file). Returns true when
     * the main file was deleted or did not exist in the first place.
     */
    public function delete(): bool
    {
        if (is_file($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        if (!is_file($this->path)) {
            return true;
        }

        return @unlink($this->path);
    }

    /** Copy the current document to "<name>.<timestamp>.bak" for backups. */
    public function backup(): ?string
    {
        if (!$this->exists()) {
            return null;
        }
        $target = $this->path . '.' . gmdate('YmdHis') . '.bak';
        if (!@copy($this->path, $target)) {
            throw new StorageException(sprintf('Unable to create backup "%s".', $target));
        }

        return $target;
    }

    /** List backup files created by backup(), oldest first. @return array<int, string> */
    public function backups(): array
    {
        $pattern = $this->path . '.*.bak';
        $files = glob($pattern);
        if ($files === false) {
            return [];
        }
        sort($files);

        return $files;
    }

    public function ensureDirectory(): void
    {
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new StorageException(sprintf('Unable to create storage directory "%s".', $dir));
        }
    }

    /**
     * Serialize to the temp file and atomically move it over the real path.
     * Must only be called while holding the lock.
     *
     * @param array<string, mixed> $data
     */
    private function writeUnderLock(array $data): void
    {
        $json = self::encode($data, $this->pretty);
        if (@file_put_contents($this->tmpPath, $json, LOCK_EX) === false) {
            throw new StorageException(sprintf('Unable to write temporary file "%s".', $this->tmpPath));
        }
        if (!@rename($this->tmpPath, $this->path)) {
            @unlink($this->tmpPath);
            throw new StorageException(sprintf('Unable to move "%s" over "%s".', $this->tmpPath, $this->path));
        }
    }

    /**
     * Open (and create if needed) the lock sidecar and take an exclusive
     * lock, retrying with a small delay when another process holds it.
     *
     * @return resource
     */
    private function acquireLock()
    {
        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new StorageException(sprintf('Unable to open lock file "%s".', $this->lockPath));
        }
        $attempt = 0;
        while (@flock($handle, LOCK_EX | LOCK_NB) === false) {
            $attempt++;
            if ($attempt >= $this->lockRetries) {
                fclose($handle);
                throw new StorageException(sprintf(
                    'Could not acquire lock for "%s" after %d attempts.',
                    $this->path,
                    $attempt
                ));
            }
            usleep($this->retryDelayMicros);
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Encode a document with predictable, diff-friendly settings.
     *
     * @param array<string, mixed> $data
     */
    public static function encode(array $data, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new StorageException('Unable to encode document: ' . json_last_error_msg());
        }

        return $json . "\n";
    }

    /**
     * Decode a document, turning JSON errors into StorageException.
     *
     * @return array<string, mixed>
     */
    private static function decode(string $raw, string $path): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new StorageException(
                sprintf('Corrupted JSON document at "%s": %s', $path, $exception->getMessage()),
                $exception
            );
        }
        if (!is_array($decoded)) {
            throw new StorageException(sprintf('JSON document at "%s" must decode to an object or array.', $path));
        }

        return $decoded;
    }
}
