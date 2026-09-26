<?php

declare(strict_types=1);

namespace Shortlink\Controller;

use Shortlink\ClickTracker;
use Shortlink\Http\Request;
use Shortlink\Http\Response;
use Shortlink\Storage\ClickRepository;
use Shortlink\Storage\LinkRepository;
use Shortlink\View\DashboardHtml;

/**
 * HTML dashboard and infrastructure endpoints.
 *
 *   GET /           302 redirect to /dashboard
 *   GET /dashboard  server-rendered stats page (no JavaScript)
 *   GET /health     JSON health probe for uptime monitors
 */
final class DashboardController
{
    public function __construct(
        private LinkRepository $links,
        private ClickRepository $clicks,
        private DashboardHtml $view,
        private string $baseUrl,
        private string $appName = 'Shortlink',
        private array $options = []
    ) {
    }

    public function home(Request $request): Response
    {
        return Response::redirect('/dashboard', 302);
    }

    /**
     * Assemble every dataset the dashboard shows and render it.
     */
    public function dashboard(Request $request): Response
    {
        $summary = $this->links->statusSummary();
        $totals = $this->clicks->totals();
        $chartDays = max(1, min(90, (int)($this->options['chart_days'] ?? 14)));

        $recentLinks = [];
        foreach ($this->links->recentlyCreated((int)($this->options['recent_links'] ?? 10)) as $record) {
            if (is_array($record)) {
                $recentLinks[] = $this->links->present($record, $this->baseUrl);
            }
        }

        $topLinks = [];
        foreach ($this->links->topByClicks((int)($this->options['top_links'] ?? 8)) as $record) {
            if (is_array($record)) {
                $topLinks[] = $this->links->present($record, $this->baseUrl);
            }
        }

        $recentClicks = [];
        foreach ($this->clicks->recentClicks(null, (int)($this->options['recent_clicks'] ?? 15)) as $click) {
            if (is_array($click)) {
                $recentClicks[] = ClickTracker::presentClick($click);
            }
        }

        $html = $this->view->render([
            'app_name' => $this->appName,
            'base_url' => $this->baseUrl,
            'summary' => $summary,
            'totals' => $totals,
            'recent_links' => $recentLinks,
            'top_links' => $topLinks,
            'recent_clicks' => $recentClicks,
            'chart' => $this->clicks->dailyTotals($chartDays),
            'chart_days' => $chartDays,
            'generated_at' => gmdate(DATE_ATOM),
        ]);

        return Response::html($html)->withSecurityHeaders(true);
    }

    /**
     * Health probe: 200 while the storage directory is writable, 503 when
     * persistence is broken. Includes a few counters for quick triage.
     */
    public function health(Request $request): Response
    {
        $storageDir = dirname($this->links->store()->path());
        $writable = is_dir($storageDir) && is_writable($storageDir);
        $summary = $this->links->statusSummary();
        $totals = $this->clicks->totals();

        return Response::json([
            'status' => $writable ? 'ok' : 'degraded',
            'time' => gmdate(DATE_ATOM),
            'storage' => [
                'path' => $storageDir,
                'writable' => $writable,
            ],
            'links' => [
                'total' => $summary['total'],
                'active' => $summary['active'],
            ],
            'clicks' => [
                'total' => $totals['total_clicks'],
                'unique_visitors' => $totals['unique_visitors'],
            ],
        ], $writable ? 200 : 503);
    }
}
