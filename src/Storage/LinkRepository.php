<?php

declare(strict_types=1);

namespace Shortlink\Storage;

use Shortlink\Exception\ConflictException;
use Shortlink\Exception\NotFoundException;

/**
 * Repository for short link records persisted through a JsonStore.
 *
 * Document layout on disk:
 *   {"version": 1, "links": {"<code>": { ...record... }}}
 *
 * Record fields:
 *   code        string  short code / custom alias (unique key)
 *   url         string  validated target URL
 *   title       string  optional human readable label (defaults to host)
 *   custom      bool    true when the code was chosen by the client
 *   created_at  string  ISO-8601 UTC
 *   updated_at  string  ISO-8601 UTC
 *   expires_at  ?string ISO-8601 UTC or null
 *   max_clicks  ?int    click limit or null
 *   is_active   bool    soft toggle, disabled links return 410
 *   clicks      int     denormalized counter kept in sync on redirect
 */
final class LinkRepository
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ALL = 'all';

    private JsonStore $store;

    public function __construct(JsonStore $store)
    {
        $this->store = $store;
    }

    public function store(): JsonStore
    {
        return $this->store;
    }

    /**
     * Insert a new link. Throws ConflictException when the code is taken.
     *
     * @param array{title?: string, custom?: bool, expires_at?: ?string, max_clicks?: ?int, is_active?: bool, created_at?: string} $options
     * @return array<string, mixed>
     */
    public function create(string $code, string $url, array $options = []): array
    {
        if ($code === '') {
            throw new \InvalidArgumentException('Short code must not be empty.');
        }
        $now = gmdate(DATE_ATOM);
        $record = [
            'code' => $code,
            'url' => $url,
            'title' => (string)($options['title'] ?? ''),
            'custom' => (bool)($options['custom'] ?? false),
            'created_at' => (string)($options['created_at'] ?? $now),
            'updated_at' => $now,
            'expires_at' => isset($options['expires_at']) && is_string($options['expires_at']) ? $options['expires_at'] : null,
            'max_clicks' => isset($options['max_clicks']) && $options['max_clicks'] !== null ? (int)$options['max_clicks'] : null,
            'is_active' => (bool)($options['is_active'] ?? true),
            'clicks' => 0,
        ];
        $this->store->mutate(static function (array $data) use ($code, $record): array {
            $links = is_array($data['links'] ?? null) ? $data['links'] : [];
            if (isset($links[$code])) {
                throw new ConflictException(sprintf('Short code "%s" is already in use.', $code));
            }
            $links[$code] = $record;
            $data['links'] = $links;
            $data['version'] = 1;

            return $data;
        });

        return $record;
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        $data = $this->store->read();
        $links = is_array($data['links'] ?? null) ? $data['links'] : [];
        $record = $links[$code] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * Like find() but throws NotFoundException instead of returning null.
     *
     * @return array<string, mixed>
     */
    public function require(string $code): array
    {
        $record = $this->find($code);
        if ($record === null) {
            throw new NotFoundException(sprintf('Short link "%s" does not exist.', $code));
        }

        return $record;
    }

    public function exists(string $code): bool
    {
        return $this->find($code) !== null;
    }

    /** @return array<string, array<string, mixed>> code => record */
    public function all(): array
    {
        $data = $this->store->read();
        $links = is_array($data['links'] ?? null) ? $data['links'] : [];

        return array_filter($links, 'is_array');
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Transform a single record under the store lock and stamp updated_at.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    public function update(string $code, callable $fn): array
    {
        $result = $this->store->mutate(static function (array $data) use ($code, $fn) {
            $links = is_array($data['links'] ?? null) ? $data['links'] : [];
            if (!isset($links[$code]) || !is_array($links[$code])) {
                throw new NotFoundException(sprintf('Short link "%s" does not exist.', $code));
            }
            $record = $fn($links[$code]);
            $record['code'] = $code;
            $record['updated_at'] = gmdate(DATE_ATOM);
            $links[$code] = $record;
            $data['links'] = $links;

            return $data;
        });
        $links = is_array($result['links'] ?? null) ? $result['links'] : [];

        return is_array($links[$code] ?? null) ? $links[$code] : [];
    }

    /** Soft enable/disable without deleting statistics. */
    public function setEnabled(string $code, bool $enabled): array
    {
        return $this->update($code, static function (array $record) use ($enabled): array {
            $record['is_active'] = $enabled;

            return $record;
        });
    }

    public function delete(string $code): bool
    {
        $found = false;
        $this->store->mutate(static function (array $data) use ($code, &$found): array {
            $links = is_array($data['links'] ?? null) ? $data['links'] : [];
            if (!isset($links[$code])) {
                return $data;
            }
            $found = true;
            unset($links[$code]);
            $data['links'] = $links;

            return $data;
        });

        return $found;
    }

    /**
     * Increment the denormalized click counter and return the new value
     * (0 when the code vanished concurrently).
     */
    public function incrementClicks(string $code): int
    {
        $count = 0;
        $this->store->mutate(static function (array $data) use ($code, &$count): array {
            $links = is_array($data['links'] ?? null) ? $data['links'] : [];
            if (isset($links[$code]) && is_array($links[$code])) {
                $links[$code]['clicks'] = ((int)($links[$code]['clicks'] ?? 0)) + 1;
                $count = (int)$links[$code]['clicks'];
                $data['links'] = $links;
            }

            return $data;
        });

        return $count;
    }

    /**
     * Paginate, filter and sort records for the admin list endpoint.
     *
     * @return array{items: array<int, array<string, mixed>>, page: int, per_page: int, total: int, pages: int}
     */
    public function paginate(
        int $page,
        int $perPage,
        string $status = self::STATUS_ALL,
        string $search = '',
        string $sort = 'newest'
    ): array {
        $now = time();
        $records = array_values($this->all());
        if ($status !== self::STATUS_ALL && $status !== '') {
            $records = array_values(array_filter(
                $records,
                static function (array $record) use ($status, $now): bool {
                    return self::computeStatus($record, $now) === $status;
                }
            ));
        }
        if ($search !== '') {
            $needle = strtolower($search);
            $records = array_values(array_filter(
                $records,
                static function (array $record) use ($needle): bool {
                    $haystack = strtolower(
                        (string)$record['code'] . ' ' . (string)$record['url'] . ' ' . (string)($record['title'] ?? '')
                    );

                    return str_contains($haystack, $needle);
                }
            ));
        }
        usort($records, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'oldest' => strcmp((string)$a['created_at'], (string)$b['created_at']),
                'most_clicks' => ((int)$b['clicks']) <=> ((int)$a['clicks']),
                'code' => strcmp((string)$a['code'], (string)$b['code']),
                default => strcmp((string)$b['created_at'], (string)$a['created_at']),
            };
        });
        $total = count($records);
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($records, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    /**
     * Counts per lifecycle status.
     *
     * @return array{total: int, active: int, expired: int, exhausted: int, disabled: int}
     */
    public function statusSummary(): array
    {
        $now = time();
        $summary = ['total' => 0, 'active' => 0, 'expired' => 0, 'exhausted' => 0, 'disabled' => 0];
        foreach ($this->all() as $record) {
            $summary['total']++;
            $status = self::computeStatus($record, $now);
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topByClicks(int $limit): array
    {
        $records = array_values($this->all());
        usort($records, static fn (array $a, array $b): int => ((int)$b['clicks']) <=> ((int)$a['clicks']));

        return array_slice($records, 0, max(0, $limit));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentlyCreated(int $limit): array
    {
        $records = array_values($this->all());
        usort($records, static fn (array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));

        return array_slice($records, 0, max(0, $limit));
    }

    /**
     * Delete links whose expires_at lies in the past. With $olderThanDays
     * only links that expired at least that long ago are removed, which
     * makes the command safe to run on a schedule.
     */
    public function pruneExpired(?int $olderThanDays = null): int
    {
        $now = time();
        $cutoff = $olderThanDays !== null ? $now - ($olderThanDays * 86400) : null;
        $removed = 0;
        $this->store->mutate(static function (array $data) use ($now, $cutoff, &$removed): array {
            $links = is_array($data['links'] ?? null) ? $data['links'] : [];
            foreach (array_keys($links) as $code) {
                $record = $links[$code];
                if (!is_array($record)) {
                    continue;
                }
                $expiresAt = $record['expires_at'] ?? null;
                if (!is_string($expiresAt) || $expiresAt === '') {
                    continue;
                }
                $ts = strtotime($expiresAt);
                if ($ts === false || $ts >= $now) {
                    continue;
                }
                if ($cutoff !== null && $ts > $cutoff) {
                    continue;
                }
                unset($links[$code]);
                $removed++;
            }
            $data['links'] = $links;

            return $data;
        });

        return $removed;
    }

    /**
     * Lifecycle status derived from the record and the current time.
     *
     * @param array<string, mixed> $record
     */
    public static function computeStatus(array $record, ?int $now = null): string
    {
        $now ??= time();
        if (!(bool)($record['is_active'] ?? true)) {
            return self::STATUS_DISABLED;
        }
        $expiresAt = $record['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts !== false && $ts < $now) {
                return self::STATUS_EXPIRED;
            }
        }
        $maxClicks = $record['max_clicks'] ?? null;
        if ($maxClicks !== null && is_numeric($maxClicks) && (int)$maxClicks > 0) {
            if ((int)($record['clicks'] ?? 0) >= (int)$maxClicks) {
                return self::STATUS_EXHAUSTED;
            }
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * Public JSON representation of a record.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function present(array $record, string $baseUrl): array
    {
        $expiresAt = $record['expires_at'] ?? null;
        $maxClicks = $record['max_clicks'] ?? null;

        return [
            'code' => (string)$record['code'],
            'short_url' => rtrim($baseUrl, '/') . '/' . rawurlencode((string)$record['code']),
            'target_url' => (string)$record['url'],
            'title' => (string)($record['title'] ?? ''),
            'custom' => (bool)($record['custom'] ?? false),
            'created_at' => (string)($record['created_at'] ?? ''),
            'updated_at' => (string)($record['updated_at'] ?? ''),
            'expires_at' => is_string($expiresAt) ? $expiresAt : null,
            'max_clicks' => $maxClicks !== null ? (int)$maxClicks : null,
            'clicks' => (int)($record['clicks'] ?? 0),
            'is_active' => (bool)($record['is_active'] ?? true),
            'status' => self::computeStatus($record),
        ];
    }
}
