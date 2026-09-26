<?php

declare(strict_types=1);

namespace Shortlink\Storage;

/**
 * Append-only click log with pre-aggregated daily buckets.
 *
 * Writing every click into one flat list would make statistics O(n) and the
 * file grow forever, so the repository maintains two structures under the
 * same lock:
 *
 *  - "log":   a bounded append-only list of raw clicks (for "recent clicks"
 *             views and CSV export), trimmed to maxLogEntries,
 *  - "daily": per-day aggregates per code (clicks, unique visitor hash set,
 *             referrer/browser/OS counters) that answer every stats query
 *             without scanning the log.
 *
 * Document layout on disk:
 * {
 *   "version": 1,
 *   "sequence": 42,
 *   "log": [{"id": 42, "code": "abc1234", "ts": 1770700000, "ip_hash": "…",
 *            "referrer": "google.com", "browser": "Chrome",
 *            "browser_version": "120.0", "os": "Windows",
 *            "device": "Desktop", "is_bot": false}],
 *   "daily": {
 *     "2026-02-10": {
 *       "total": 12, "bots": 2,
 *       "codes": {
 *         "abc1234": {"clicks": 5, "bots": 1,
 *                     "visitors": {"3f9a…": true},
 *                     "referrers": {"google.com": 3},
 *                     "browsers": {"Chrome": 4},
 *                     "os": {"Windows": 5}}
 *       }
 *     }
 *   }
 * }
 */
final class ClickRepository
{
    private JsonStore $store;

    private int $maxLogEntries;

    public function __construct(JsonStore $store, int $maxLogEntries = 20000)
    {
        $this->store = $store;
        $this->maxLogEntries = max(100, $maxLogEntries);
    }

    public function store(): JsonStore
    {
        return $this->store;
    }

    /**
     * Append a click and update the daily aggregates in one locked pass.
     *
     * @param array{code: string, ts: int, ip_hash: string, referrer: string, browser: string, browser_version: string, os: string, device: string, is_bot: bool} $click
     * @return array<string, mixed> the stored click including its assigned id
     */
    public function append(array $click): array
    {
        $stored = [];
        $maxLogEntries = $this->maxLogEntries;
        $this->store->mutate(static function (array $data) use ($click, $maxLogEntries, &$stored): array {
            $sequence = ((int)($data['sequence'] ?? 0)) + 1;
            $click['id'] = $sequence;

            $log = is_array($data['log'] ?? null) ? $data['log'] : [];
            $log[] = $click;
            if (count($log) > $maxLogEntries) {
                $log = array_slice($log, count($log) - $maxLogEntries);
            }

            $date = gmdate('Y-m-d', (int)($click['ts'] ?? 0));
            $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
            $bucket = is_array($daily[$date] ?? null) ? $daily[$date] : ['total' => 0, 'bots' => 0, 'codes' => []];
            $bucket['total'] = (int)($bucket['total'] ?? 0) + 1;

            $code = (string)($click['code'] ?? '');
            $codes = is_array($bucket['codes'] ?? null) ? $bucket['codes'] : [];
            $entry = is_array($codes[$code] ?? null)
                ? $codes[$code]
                : ['clicks' => 0, 'bots' => 0, 'visitors' => [], 'referrers' => [], 'browsers' => [], 'os' => []];
            $entry['clicks'] = (int)($entry['clicks'] ?? 0) + 1;

            if ((bool)($click['is_bot'] ?? false)) {
                $bucket['bots'] = (int)($bucket['bots'] ?? 0) + 1;
                $entry['bots'] = (int)($entry['bots'] ?? 0) + 1;
            } else {
                $visitors = is_array($entry['visitors'] ?? null) ? $entry['visitors'] : [];
                $visitors[(string)($click['ip_hash'] ?? '')] = true;
                $entry['visitors'] = $visitors;
            }

            $referrer = (string)($click['referrer'] ?? '');
            if ($referrer !== '') {
                $referrers = is_array($entry['referrers'] ?? null) ? $entry['referrers'] : [];
                $referrers[$referrer] = (int)($referrers[$referrer] ?? 0) + 1;
                $entry['referrers'] = $referrers;
            }

            $browsers = is_array($entry['browsers'] ?? null) ? $entry['browsers'] : [];
            $browser = (string)($click['browser'] ?? 'Unknown');
            $browsers[$browser] = (int)($browsers[$browser] ?? 0) + 1;
            $entry['browsers'] = $browsers;

            $oses = is_array($entry['os'] ?? null) ? $entry['os'] : [];
            $os = (string)($click['os'] ?? 'Unknown');
            $oses[$os] = (int)($oses[$os] ?? 0) + 1;
            $entry['os'] = $oses;

            $codes[$code] = $entry;
            $bucket['codes'] = $codes;
            $daily[$date] = $bucket;

            $data['daily'] = $daily;
            $data['log'] = $log;
            $data['sequence'] = $sequence;
            $data['version'] = 1;
            $stored = $click;

            return $data;
        });

        return $stored;
    }

    /**
     * Total number of tracked clicks for a code across all days.
     */
    public function totalClicks(string $code): int
    {
        $total = 0;
        foreach ($this->codeEntries($code) as $entry) {
            $total += (int)($entry['clicks'] ?? 0);
        }

        return $total;
    }

    /**
     * Number of distinct privacy-preserving visitor hashes seen for a code
     * over all time. Unique per day buckets are unioned here, so the value
     * is "unique-ish": the same visitor on two days counts twice.
     */
    public function uniqueVisitors(string $code): int
    {
        $seen = [];
        foreach ($this->codeEntries($code) as $entry) {
            foreach ((array)($entry['visitors'] ?? []) as $hash => $flag) {
                $seen[(string)$hash] = true;
            }
        }

        return count($seen);
    }

    public function botClicks(string $code): int
    {
        $total = 0;
        foreach ($this->codeEntries($code) as $entry) {
            $total += (int)($entry['bots'] ?? 0);
        }

        return $total;
    }

    /**
     * Full analytics payload for one code over a rolling window of days.
     *
     * @return array{code: string, total_clicks: int, unique_visitors: int, bot_clicks: int, clicks_today: int, daily: array<int, array{date: string, clicks: int}>, referrers: array<int, array{referrer: string, clicks: int}>, browsers: array<int, array{browser: string, clicks: int}>, os: array<int, array{os: string, clicks: int}>, recent: array<int, array<string, mixed>>}
     */
    public function statsFor(string $code, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $data = $this->store->read();
        $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];

        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $buckets[gmdate('Y-m-d', time() - ($i * 86400))] = 0;
        }
        $today = gmdate('Y-m-d');
        $total = 0;
        $bots = 0;
        $todayCount = 0;
        $visitors = [];
        $referrers = [];
        $browsers = [];
        $oses = [];

        foreach ($daily as $date => $bucket) {
            if (!is_string($date) || !is_array($bucket)) {
                continue;
            }
            $entry = $bucket['codes'][$code] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $clicks = (int)($entry['clicks'] ?? 0);
            $total += $clicks;
            $bots += (int)($entry['bots'] ?? 0);
            if (array_key_exists($date, $buckets)) {
                $buckets[$date] = $clicks;
            }
            if ($date === $today) {
                $todayCount = $clicks;
            }
            foreach ((array)($entry['visitors'] ?? []) as $hash => $flag) {
                $visitors[(string)$hash] = true;
            }
            foreach ((array)($entry['referrers'] ?? []) as $referrer => $count) {
                $referrers[(string)$referrer] = ((int)($referrers[(string)$referrer] ?? 0)) + (int)$count;
            }
            foreach ((array)($entry['browsers'] ?? []) as $browser => $count) {
                $browsers[(string)$browser] = ((int)($browsers[(string)$browser] ?? 0)) + (int)$count;
            }
            foreach ((array)($entry['os'] ?? []) as $os => $count) {
                $oses[(string)$os] = ((int)($oses[(string)$os] ?? 0)) + (int)$count;
            }
        }

        $dailyRows = [];
        foreach ($buckets as $date => $clicks) {
            $dailyRows[] = ['date' => $date, 'clicks' => $clicks];
        }

        $top = static function (array $counts, string $key, int $limit): array {
            arsort($counts);
            $rows = [];
            foreach ($counts as $label => $count) {
                $rows[] = [$key => (string)$label, 'clicks' => (int)$count];
                if (count($rows) >= $limit) {
                    break;
                }
            }

            return $rows;
        };

        return [
            'code' => $code,
            'total_clicks' => $total,
            'unique_visitors' => count($visitors),
            'bot_clicks' => $bots,
            'clicks_today' => $todayCount,
            'daily' => $dailyRows,
            'referrers' => $top($referrers, 'referrer', 10),
            'browsers' => $top($browsers, 'browser', 10),
            'os' => $top($oses, 'os', 10),
            'recent' => $this->recentClicks($code, 20),
        ];
    }

    /**
     * Newest raw clicks, optionally restricted to one code.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentClicks(?string $code = null, int $limit = 20): array
    {
        $data = $this->store->read();
        $log = is_array($data['log'] ?? null) ? $data['log'] : [];
        $rows = [];
        for ($i = count($log) - 1; $i >= 0 && count($rows) < $limit; $i--) {
            $click = $log[$i];
            if (!is_array($click)) {
                continue;
            }
            if ($code !== null && (string)($click['code'] ?? '') !== $code) {
                continue;
            }
            $rows[] = $click;
        }

        return $rows;
    }

    /**
     * Global per-day click totals (all codes combined), used by the
     * dashboard chart.
     *
     * @return array<int, array{date: string, clicks: int}>
     */
    public function dailyTotals(int $days): array
    {
        $days = max(1, min(365, $days));
        $data = $this->store->read();
        $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $buckets[gmdate('Y-m-d', time() - ($i * 86400))] = 0;
        }
        foreach ($daily as $date => $bucket) {
            if (is_string($date) && array_key_exists($date, $buckets) && is_array($bucket)) {
                $buckets[$date] = (int)($bucket['total'] ?? 0);
            }
        }
        $rows = [];
        foreach ($buckets as $date => $clicks) {
            $rows[] = ['date' => $date, 'clicks' => $clicks];
        }

        return $rows;
    }

    /**
     * Codes ranked by click count, best first.
     *
     * @return array<int, array{code: string, clicks: int}>
     */
    public function topCodes(int $limit = 10): array
    {
        $counts = [];
        foreach ($this->store->read()['daily'] ?? [] as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            foreach ((array)($bucket['codes'] ?? []) as $code => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $key = (string)$code;
                $counts[$key] = ((int)($counts[$key] ?? 0)) + (int)($entry['clicks'] ?? 0);
            }
        }
        arsort($counts);
        $rows = [];
        foreach ($counts as $code => $clicks) {
            $rows[] = ['code' => $code, 'clicks' => (int)$clicks];
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Global totals for the dashboard cards and the health endpoint.
     *
     * @return array{total_clicks: int, unique_visitors: int, bot_clicks: int, tracked_days: int, log_entries: int}
     */
    public function totals(): array
    {
        $data = $this->store->read();
        $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
        $total = 0;
        $bots = 0;
        $visitors = [];
        foreach ($daily as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $total += (int)($bucket['total'] ?? 0);
            $bots += (int)($bucket['bots'] ?? 0);
            foreach ((array)($bucket['codes'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                foreach ((array)($entry['visitors'] ?? []) as $hash => $flag) {
                    $visitors[(string)$hash] = true;
                }
            }
        }
        $log = is_array($data['log'] ?? null) ? $data['log'] : [];

        return [
            'total_clicks' => $total,
            'unique_visitors' => count($visitors),
            'bot_clicks' => $bots,
            'tracked_days' => count($daily),
            'log_entries' => count($log),
        ];
    }

    /**
     * Drop daily buckets and raw log rows older than $days. Returns the
     * number of removed buckets plus removed raw log rows.
     */
    public function pruneOlderThan(int $days): int
    {
        if ($days < 1) {
            return 0;
        }
        $cutoffDate = gmdate('Y-m-d', time() - ($days * 86400));
        $cutoffTs = time() - ($days * 86400);
        $removed = 0;
        $this->store->mutate(static function (array $data) use ($cutoffDate, $cutoffTs, &$removed): array {
            $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
            foreach (array_keys($daily) as $date) {
                if (is_string($date) && $date < $cutoffDate) {
                    unset($daily[$date]);
                    $removed++;
                }
            }
            $log = is_array($data['log'] ?? null) ? $data['log'] : [];
            $kept = [];
            foreach ($log as $click) {
                if (is_array($click) && (int)($click['ts'] ?? 0) >= $cutoffTs) {
                    $kept[] = $click;
                }
            }
            $removed += count($log) - count($kept);
            $data['daily'] = $daily;
            $data['log'] = $kept;

            return $data;
        });

        return $removed;
    }

    /**
     * Remove every trace of a code (called after deleting a link).
     */
    public function clearCode(string $code): bool
    {
        $found = false;
        $this->store->mutate(static function (array $data) use ($code, &$found): array {
            $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
            foreach ($daily as $date => $bucket) {
                if (!is_array($bucket)) {
                    continue;
                }
                $codes = is_array($bucket['codes'] ?? null) ? $bucket['codes'] : [];
                if (isset($codes[$code])) {
                    $found = true;
                    unset($codes[$code]);
                    $bucket['codes'] = $codes;
                }
                $total = 0;
                $bots = 0;
                foreach ((array)($bucket['codes'] ?? []) as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $total += (int)($entry['clicks'] ?? 0);
                    $bots += (int)($entry['bots'] ?? 0);
                }
                $bucket['total'] = $total;
                $bucket['bots'] = $bots;
                $daily[$date] = $bucket;
            }
            $log = is_array($data['log'] ?? null) ? $data['log'] : [];
            $kept = [];
            foreach ($log as $click) {
                if (is_array($click) && (string)($click['code'] ?? '') === $code) {
                    continue;
                }
                $kept[] = $click;
            }
            $data['daily'] = $daily;
            $data['log'] = $kept;

            return $data;
        });

        return $found;
    }

    /**
     * Raw log rows for CSV/JSON export (oldest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportLog(): array
    {
        $data = $this->store->read();
        $log = is_array($data['log'] ?? null) ? $data['log'] : [];

        return array_values(array_filter($log, 'is_array'));
    }

    public function logSize(): int
    {
        $data = $this->store->read();
        $log = is_array($data['log'] ?? null) ? $data['log'] : [];

        return count($log);
    }

    public function documentSize(): int
    {
        return $this->store->size();
    }

    /**
     * All per-day aggregate entries recorded for one code.
     *
     * @return array<int, array<string, mixed>>
     */
    private function codeEntries(string $code): array
    {
        $data = $this->store->read();
        $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
        $entries = [];
        foreach ($daily as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $entry = $bucket['codes'][$code] ?? null;
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
