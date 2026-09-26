<?php

declare(strict_types=1);

namespace Shortlink\View;

/**
 * Server-side HTML renderer for the dashboard and the error pages.
 *
 * Every dynamic value passes through the e() escaper (htmlspecialchars with
 * ENT_QUOTES). The markup ships zero JavaScript, which allows a strict
 * Content-Security-Policy on the responses produced by the controllers.
 */
final class DashboardHtml
{
    private string $baseUrl;

    private string $appName;

    public function __construct(string $baseUrl, string $appName = 'Shortlink')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->appName = $appName;
    }

    /**
     * Full dashboard page.
     *
     * @param array<string, mixed> $data
     */
    public function render(array $data): string
    {
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];
        $chart = is_array($data['chart'] ?? null) ? $data['chart'] : [];
        $chartDays = (int)($data['chart_days'] ?? count($chart));
        $recentLinks = is_array($data['recent_links'] ?? null) ? $data['recent_links'] : [];
        $topLinks = is_array($data['top_links'] ?? null) ? $data['top_links'] : [];
        $recentClicks = is_array($data['recent_clicks'] ?? null) ? $data['recent_clicks'] : [];
        $generated = (string)($data['generated_at'] ?? '');

        $content = $this->statCards($summary, $totals)
            . $this->barChart($chart, $chartDays)
            . '<div class="grid">'
            . $this->recentLinksTable($recentLinks)
            . $this->topLinksTable($topLinks)
            . '</div>'
            . $this->recentClicksTable($recentClicks)
            . '<p class="muted generated">Generated at ' . self::e($generated) . ' (UTC)</p>';

        return $this->layout('Dashboard — ' . $this->appName, $content);
    }

    /** Styled 404 page for unknown codes. */
    public function renderNotFound(string $code, string $dashboardUrl): string
    {
        $detail = $code === ''
            ? '<p class="muted">No short code was provided.</p>'
            : '<p class="muted">No link is registered for the code <code>' . self::e($code) . '</code>.</p>';
        $content = '<div class="error-page">'
            . '<p class="error-code">404</p>'
            . '<h1>Short link not found</h1>'
            . $detail
            . '<p><a class="button" href="' . self::e($dashboardUrl) . '">Open the dashboard</a></p>'
            . '</div>';

        return $this->layout('Not found — ' . $this->appName, $content);
    }

    /** Styled 410 page for expired / disabled / exhausted links. */
    public function renderGone(string $code, string $reason, string $dashboardUrl): string
    {
        $content = '<div class="error-page">'
            . '<p class="error-code">410</p>'
            . '<h1>This short link is gone</h1>'
            . '<p class="muted">Code <code>' . self::e($code) . '</code> — ' . self::e($reason) . '</p>'
            . '<p><a class="button" href="' . self::e($dashboardUrl) . '">Open the dashboard</a></p>'
            . '</div>';

        return $this->layout('Link unavailable — ' . $this->appName, $content);
    }

    /** Styled 429 page shown when a visitor exceeds the redirect limit. */
    public function renderRateLimited(int $retryAfter, string $dashboardUrl): string
    {
        $content = '<div class="error-page">'
            . '<p class="error-code">429</p>'
            . '<h1>Too many requests</h1>'
            . '<p class="muted">You are clicking through links unusually fast. '
            . 'Please retry in ' . self::number($retryAfter) . ' seconds.</p>'
            . '<p><a class="button" href="' . self::e($dashboardUrl) . '">Open the dashboard</a></p>'
            . '</div>';

        return $this->layout('Slow down — ' . $this->appName, $content);
    }

    /** Generic error page used by the front controller for 5xx. */
    public function renderErrorPage(int $status, string $title, string $message): string
    {
        $content = '<div class="error-page">'
            . '<p class="error-code">' . self::number($status) . '</p>'
            . '<h1>' . self::e($title) . '</h1>'
            . '<p class="muted">' . self::e($message) . '</p>'
            . '<p><a class="button" href="/dashboard">Open the dashboard</a></p>'
            . '</div>';

        return $this->layout('Error ' . $status . ' — ' . $this->appName, $content);
    }

    // ------------------------------------------------------------------
    // Building blocks
    // ------------------------------------------------------------------

    /** Full HTML document with header/footer around the given content. */
    private function layout(string $title, string $content): string
    {
        return '<!doctype html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . self::e($title) . '</title>'
            . '<link rel="stylesheet" href="/assets/style.css">'
            . '</head>'
            . '<body>'
            . '<header class="topbar"><div class="wrap">'
            . '<span class="brand">' . self::e($this->appName) . '</span>'
            . '<nav>'
            . '<a href="/dashboard">Dashboard</a>'
            . '<a href="' . self::e($this->baseUrl) . '/health">Health</a>'
            . '</nav>'
            . '</div></header>'
            . '<main class="wrap">' . $content . '</main>'
            . '<footer class="wrap footer muted">Self-hosted URL shortener · ' . self::e($this->appName) . '</footer>'
            . '</body>'
            . '</html>';
    }

    /**
     * Four KPI cards on top of the dashboard.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $totals
     */
    private function statCards(array $summary, array $totals): string
    {
        $cards = [
            ['label' => 'Total links', 'value' => (int)($summary['total'] ?? 0)],
            ['label' => 'Active links', 'value' => (int)($summary['active'] ?? 0)],
            ['label' => 'Total clicks', 'value' => (int)($totals['total_clicks'] ?? 0)],
            ['label' => 'Unique visitors', 'value' => (int)($totals['unique_visitors'] ?? 0)],
        ];
        $html = '<section class="cards">';
        foreach ($cards as $card) {
            $html .= '<div class="card">'
                . '<span class="card-value">' . self::number((int)$card['value']) . '</span>'
                . '<span class="card-label">' . self::e((string)$card['label']) . '</span>'
                . '</div>';
        }

        return $html . '</section>';
    }

    /**
     * Pure-CSS bar chart of global clicks per day. Bar heights map to one
     * of eleven level classes so the strict CSP can stay free of inline
     * style attributes.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function barChart(array $rows, int $days): string
    {
        if ($rows === []) {
            return $this->panel('Clicks per day', '<p class="muted">No click data yet.</p>', 'last ' . self::number($days) . ' days');
        }
        $max = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $max = max($max, (int)($row['clicks'] ?? 0));
            }
        }
        if ($max === 0) {
            $max = 1;
        }
        $bars = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = (string)($row['date'] ?? '');
            $clicks = (int)($row['clicks'] ?? 0);
            $level = (int)min(10, ceil(($clicks / $max) * 10));
            $bars .= '<div class="bar" title="' . self::e($date . ': ' . self::number($clicks) . ' clicks') . '">'
                . '<div class="bar-fill lvl-' . $level . '"></div>'
                . '<span class="bar-label">' . self::e(self::shortDate($date)) . '</span>'
                . '</div>';
        }

        return $this->panel(
            'Clicks per day',
            '<div class="bars">' . $bars . '</div>',
            'peak ' . self::number($max) . '/day'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $links
     */
    private function recentLinksTable(array $links): string
    {
        if ($links === []) {
            return $this->panel(
                'Recently created links',
                '<p class="muted">No links yet. Create the first one with <code>POST /api/links</code>.</p>'
            );
        }
        $rows = '';
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $code = (string)($link['code'] ?? '');
            $shortUrl = (string)($link['short_url'] ?? '');
            $target = (string)($link['target_url'] ?? '');
            $status = (string)($link['status'] ?? 'active');
            $createdAt = (string)($link['created_at'] ?? '');
            $rows .= '<tr>'
                . '<td><a class="code-link" href="' . self::e($shortUrl) . '">' . self::e($code) . '</a></td>'
                . '<td class="ellipsis"><a href="' . self::e($target) . '" rel="noopener noreferrer">'
                . self::e(self::truncate($target, 44)) . '</a></td>'
                . '<td>' . $this->statusBadge($status) . '</td>'
                . '<td class="num">' . self::number((int)($link['clicks'] ?? 0)) . '</td>'
                . '<td class="muted">' . self::e(self::timeAgoFromIso($createdAt)) . '</td>'
                . '</tr>';
        }

        return $this->panel(
            'Recently created links',
            '<table class="table"><thead><tr><th>Code</th><th>Target</th><th>Status</th>'
            . '<th class="num">Clicks</th><th>Created</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $links
     */
    private function topLinksTable(array $links): string
    {
        if ($links === []) {
            return $this->panel('Top links', '<p class="muted">No clicks recorded yet.</p>');
        }
        $totalClicks = 0;
        foreach ($links as $link) {
            if (is_array($link)) {
                $totalClicks += (int)($link['clicks'] ?? 0);
            }
        }
        $rows = '';
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $clicks = (int)($link['clicks'] ?? 0);
            $share = $totalClicks > 0 ? (int)round(($clicks / $totalClicks) * 100) : 0;
            $shortUrl = (string)($link['short_url'] ?? '');
            $target = (string)($link['target_url'] ?? '');
            $rows .= '<tr>'
                . '<td><a class="code-link" href="' . self::e($shortUrl) . '">' . self::e((string)($link['code'] ?? '')) . '</a></td>'
                . '<td class="num">' . self::number($clicks) . '</td>'
                . '<td class="num muted">' . self::number($share) . '%</td>'
                . '<td class="ellipsis muted"><a href="' . self::e($target) . '" rel="noopener noreferrer">'
                . self::e(self::truncate($target, 36)) . '</a></td>'
                . '</tr>';
        }

        return $this->panel(
            'Top links',
            '<table class="table"><thead><tr><th>Code</th><th class="num">Clicks</th>'
            . '<th class="num">Share</th><th>Target</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $clicks
     */
    private function recentClicksTable(array $clicks): string
    {
        if ($clicks === []) {
            return $this->panel('Recent clicks', '<p class="muted">No clicks recorded yet.</p>');
        }
        $rows = '';
        foreach ($clicks as $click) {
            if (!is_array($click)) {
                continue;
            }
            $ts = strtotime((string)($click['clicked_at'] ?? ''));
            $rows .= '<tr>'
                . '<td class="muted">' . self::e($ts !== false ? self::timeAgo($ts) : '—') . '</td>'
                . '<td>' . self::e((string)($click['code'] ?? '')) . '</td>'
                . '<td>' . self::e(self::truncate((string)($click['referrer'] ?? 'direct'), 28)) . '</td>'
                . '<td>' . self::e((string)($click['browser'] ?? 'Unknown')) . '</td>'
                . '<td>' . self::e((string)($click['os'] ?? 'Unknown')) . '</td>'
                . '<td>' . self::e((string)($click['device'] ?? 'Unknown')) . '</td>'
                . '<td>' . ((bool)($click['is_bot'] ?? false) ? '<span class="badge badge-disabled">bot</span>' : '—') . '</td>'
                . '</tr>';
        }

        return $this->panel(
            'Recent clicks',
            '<table class="table"><thead><tr><th>When</th><th>Code</th><th>Referrer</th><th>Browser</th>'
            . '<th>OS</th><th>Device</th><th>Bot</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        );
    }

    private function panel(string $title, string $body, ?string $subtitle = null): string
    {
        return '<section class="panel">'
            . '<div class="panel-head"><h2>' . self::e($title) . '</h2>'
            . ($subtitle !== null ? '<span class="muted">' . self::e($subtitle) . '</span>' : '')
            . '</div>'
            . $body
            . '</section>';
    }

    private function statusBadge(string $status): string
    {
        $label = match ($status) {
            'active' => 'Active',
            'expired' => 'Expired',
            'exhausted' => 'Limit reached',
            'disabled' => 'Disabled',
            default => ucfirst($status),
        };

        return '<span class="badge badge-' . self::e($status) . '">' . self::e($label) . '</span>';
    }

    // ------------------------------------------------------------------
    // Small shared helpers (public: the console reuses the formatters)
    // ------------------------------------------------------------------

    /** HTML-escape any scalar for embedding in markup. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Truncate a string, keeping whole words where possible. */
    public static function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return rtrim(substr($value, 0, max(1, $length - 1))) . '…';
    }

    /** "42,133" style grouping for dashboard numbers. */
    public static function number(int $value): string
    {
        return number_format((float)$value, 0, '.', ',');
    }

    /** "3m ago" relative time for unix timestamps. */
    public static function timeAgo(int $timestamp, int $now = 0): string
    {
        $now = $now > 0 ? $now : time();
        $diff = $now - $timestamp;
        if ($diff < 0) {
            return 'in the future';
        }
        if ($diff < 5) {
            return 'just now';
        }
        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return (string)floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return (string)floor($diff / 3600) . 'h ago';
        }
        if ($diff < 2592000) {
            return (string)floor($diff / 86400) . 'd ago';
        }

        return gmdate('Y-m-d', $timestamp);
    }

    /** Relative time from an ISO-8601 string (dash when unparseable). */
    public static function timeAgoFromIso(string $iso): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return '—';
        }

        return self::timeAgo($ts);
    }

    /** "02-10" compact day label for chart axes. */
    public static function shortDate(string $date): string
    {
        return strlen($date) >= 10 ? substr($date, 5) : $date;
    }
}
