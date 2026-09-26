<?php

declare(strict_types=1);

namespace Shortlink;

use Closure;
use Shortlink\Storage\JsonStore;

/**
 * Fixed-window rate limiter persisted through a JsonStore.
 *
 * Limits survive process restarts and are shared between all FPM workers
 * because the counters live in the storage directory instead of APCu or
 * process memory. The window is aligned to unix-time boundaries, so the
 * reset point is stable and cheap to compute:
 *
 *   start    = now - (now % windowSeconds)
 *   reset_at = start + windowSeconds
 *
 * An injectable clock (constructor argument) keeps the class unit-testable
 * without sleeping through real seconds.
 *
 * Document layout on disk:
 *   {"version": 1, "windows": {"<key>": {"count": 3, "start": 1770, "reset_at": 1830}}}
 */
final class RateLimiter
{
    private JsonStore $store;

    private Closure $clock;

    public function __construct(JsonStore $store, ?callable $clock = null)
    {
        $this->store = $store;
        if ($clock === null) {
            $this->clock = static fn (): int => time();

            return;
        }
        $this->clock = $clock instanceof Closure ? $clock : Closure::fromCallable($clock);
    }

    /**
     * Record one hit for $key and decide whether the caller is still
     * within the limit.
     *
     * @return array{allowed: bool, remaining: int, limit: int, reset_at: int, retry_after: int}
     */
    public function hit(string $key, int $max, int $windowSeconds): array
    {
        $max = max(1, $max);
        $windowSeconds = max(1, $windowSeconds);
        $now = ($this->clock)();
        $result = [
            'allowed' => false,
            'remaining' => 0,
            'limit' => $max,
            'reset_at' => $now,
            'retry_after' => 0,
        ];
        $this->store->mutate(static function (array $data) use ($key, $max, $windowSeconds, $now, &$result): array {
            $windows = is_array($data['windows'] ?? null) ? $data['windows'] : [];
            $record = is_array($windows[$key] ?? null) ? $windows[$key] : [];
            $resetAt = (int)($record['reset_at'] ?? 0);
            if ($resetAt <= $now) {
                $record = [
                    'count' => 0,
                    'start' => $now - ($now % $windowSeconds),
                    'reset_at' => $now + $windowSeconds - ($now % $windowSeconds),
                ];
            }
            $record['count'] = (int)($record['count'] ?? 0) + 1;
            $windows[$key] = $record;
            $allowed = (int)$record['count'] <= $max;
            $result = [
                'allowed' => $allowed,
                'remaining' => $allowed ? max(0, $max - (int)$record['count']) : 0,
                'limit' => $max,
                'reset_at' => (int)$record['reset_at'],
                'retry_after' => $allowed ? 0 : max(1, (int)$record['reset_at'] - $now),
            ];
            // Opportunistic garbage collection keeps the document small.
            if (count($windows) > 512) {
                $windows = array_filter(
                    $windows,
                    static fn (array $window): bool => (int)($window['reset_at'] ?? 0) > $now
                );
            }
            $data['windows'] = $windows;
            $data['version'] = 1;

            return $data;
        });

        return $result;
    }

    /**
     * Convenience wrapper returning only the verdict.
     */
    public function tooManyAttempts(string $key, int $max, int $windowSeconds): bool
    {
        return !$this->hit($key, $max, $windowSeconds)['allowed'];
    }

    /**
     * Inspect the current window without consuming an attempt.
     *
     * @return array{count: int, reset_at: int}|null
     */
    public function peek(string $key): ?array
    {
        $data = $this->store->read();
        $windows = is_array($data['windows'] ?? null) ? $data['windows'] : [];
        $record = $windows[$key] ?? null;
        if (!is_array($record)) {
            return null;
        }

        return [
            'count' => (int)($record['count'] ?? 0),
            'reset_at' => (int)($record['reset_at'] ?? 0),
        ];
    }

    /**
     * Reset a single window (e.g. after a successful login attempt).
     */
    public function clear(string $key): bool
    {
        $removed = false;
        $this->store->mutate(static function (array $data) use ($key, &$removed): array {
            $windows = is_array($data['windows'] ?? null) ? $data['windows'] : [];
            if (isset($windows[$key])) {
                $removed = true;
                unset($windows[$key]);
            }
            $data['windows'] = $windows;

            return $data;
        });

        return $removed;
    }

    /**
     * Drop every window whose reset time has passed. Returns the number
     * of removed entries; safe to call on a schedule from the console.
     */
    public function prune(): int
    {
        $now = ($this->clock)();
        $removed = 0;
        $this->store->mutate(static function (array $data) use ($now, &$removed): array {
            $windows = is_array($data['windows'] ?? null) ? $data['windows'] : [];
            foreach (array_keys($windows) as $key) {
                $record = $windows[$key];
                if (is_array($record) && (int)($record['reset_at'] ?? 0) <= $now) {
                    unset($windows[$key]);
                    $removed++;
                }
            }
            $data['windows'] = $windows;

            return $data;
        });

        return $removed;
    }

    /** Number of tracked windows right now (mostly for tests/console). */
    public function windowCount(): int
    {
        $data = $this->store->read();
        $windows = is_array($data['windows'] ?? null) ? $data['windows'] : [];

        return count($windows);
    }
}
