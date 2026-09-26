<?php

declare(strict_types=1);

namespace Shortlink\Controller;

use Shortlink\ClickTracker;
use Shortlink\Http\Request;
use Shortlink\Http\Response;
use Shortlink\Storage\ClickRepository;
use Shortlink\Storage\LinkRepository;

/**
 * GET /api/links/{code}/stats — public click analytics for one link.
 *
 * Query parameters:
 *   days  int  rolling window for the daily buckets, 1..365 (default 30)
 *
 * The payload combines link metadata, lifetime totals, the daily bucket
 * series, top referrers/browsers/OS values and the newest raw clicks.
 * Visitor identity is only ever exposed as the truncated IP hash.
 */
final class StatsController
{
    public function __construct(
        private LinkRepository $links,
        private ClickRepository $clicks,
        private string $baseUrl
    ) {
    }

    public function stats(Request $request, array $params = []): Response
    {
        $code = (string)($params['code'] ?? '');
        $record = $this->links->require($code);
        $days = $this->clampDays($request->getQueryParam('days', '30'));
        $stats = $this->clicks->statsFor($code, $days);

        $daily = is_array($stats['daily'] ?? null) ? $stats['daily'] : [];
        $clicksInWindow = 0;
        foreach ($daily as $bucket) {
            if (is_array($bucket)) {
                $clicksInWindow += (int)($bucket['clicks'] ?? 0);
            }
        }

        $recentRaw = is_array($stats['recent'] ?? null) ? $stats['recent'] : [];
        $recent = [];
        foreach ($recentRaw as $click) {
            if (is_array($click)) {
                $recent[] = ClickTracker::presentClick($click);
            }
        }

        return Response::json([
            'link' => $this->links->present($record, $this->baseUrl),
            'totals' => [
                'clicks' => (int)($stats['total_clicks'] ?? 0),
                'unique_visitors' => (int)($stats['unique_visitors'] ?? 0),
                'bot_clicks' => (int)($stats['bot_clicks'] ?? 0),
                'clicks_today' => (int)($stats['clicks_today'] ?? 0),
                'window_days' => $days,
                'clicks_in_window' => $clicksInWindow,
            ],
            'daily' => $daily,
            'referrers' => is_array($stats['referrers'] ?? null) ? $stats['referrers'] : [],
            'browsers' => is_array($stats['browsers'] ?? null) ? $stats['browsers'] : [],
            'os' => is_array($stats['os'] ?? null) ? $stats['os'] : [],
            'recent_clicks' => $recent,
            'generated_at' => gmdate(DATE_ATOM),
        ]);
    }

    /** Parse and clamp the "days" query parameter (default 30, max 365). */
    private function clampDays(?string $raw): int
    {
        if ($raw === null || preg_match('/^\d+$/', trim($raw)) !== 1) {
            return 30;
        }

        return max(1, min(365, (int)trim($raw)));
    }
}
