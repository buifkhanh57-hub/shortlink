<?php

declare(strict_types=1);

namespace Shortlink\Controller;

use Shortlink\ClickTracker;
use Shortlink\Http\Request;
use Shortlink\Http\Response;
use Shortlink\RateLimiter;
use Shortlink\Storage\LinkRepository;
use Shortlink\View\DashboardHtml;

/**
 * GET /{code} — resolve a short code and 302-redirect to the target.
 *
 * Side effects: one click row is appended to the click log and the
 * denormalized counter on the link record is incremented. Inactive,
 * expired and exhausted links answer with a styled 410 page instead of a
 * redirect; unknown codes answer with 404. HTML or JSON is chosen by the
 * Accept header so API clients get machine readable errors.
 */
final class RedirectController
{
    public function __construct(
        private LinkRepository $links,
        private ClickTracker $tracker,
        private RateLimiter $limiter,
        private DashboardHtml $view,
        private string $baseUrl,
        private array $trustedProxies = [],
        private int $rateMax = 120,
        private int $rateWindowSeconds = 60,
        private bool $rateLimitEnabled = true
    ) {
    }

    public function redirect(Request $request, array $params = []): Response
    {
        $code = (string)($params['code'] ?? '');
        if ($code === '') {
            return $this->notFound($request, '');
        }

        if ($this->rateLimitEnabled) {
            $ip = $request->getClientIp($this->trustedProxies);
            $limit = $this->limiter->hit('redirect|' . $ip, $this->rateMax, $this->rateWindowSeconds);
            if (!$limit['allowed']) {
                return Response::html(
                    $this->view->renderRateLimited($limit['retry_after'], '/dashboard'),
                    429
                )->withHeader('Retry-After', (string)$limit['retry_after'])
                 ->withSecurityHeaders(true);
            }
        }

        $record = $this->links->find($code);
        if ($record === null) {
            return $this->notFound($request, $code);
        }

        $status = LinkRepository::computeStatus($record);
        if ($status !== LinkRepository::STATUS_ACTIVE) {
            return Response::html(
                $this->view->renderGone($code, self::goneReason($status, $record), '/dashboard'),
                410
            )->withSecurityHeaders(true);
        }

        $click = $this->tracker->track($request, $code, null, $this->trustedProxies);
        $this->links->incrementClicks($code);

        return Response::redirect((string)$record['url'], 302)
            ->withHeader('X-Shortlink-Code', $code)
            ->withHeader('X-Shortlink-Bot', (bool)$click['is_bot'] ? '1' : '0');
    }

    /** Map a non-active lifecycle status to an explanatory sentence. */
    private static function goneReason(string $status, array $record): string
    {
        return match ($status) {
            LinkRepository::STATUS_EXPIRED => sprintf(
                'This short link expired on %s.',
                (string)($record['expires_at'] ?? 'an unknown date')
            ),
            LinkRepository::STATUS_EXHAUSTED => sprintf(
                'This short link reached its click limit of %d.',
                (int)($record['max_clicks'] ?? 0)
            ),
            LinkRepository::STATUS_DISABLED => 'This short link has been disabled by its owner.',
            default => 'This short link is no longer available.',
        };
    }

    private function notFound(Request $request, string $code): Response
    {
        if ($request->wantsJson()) {
            return Response::errorPayload(
                404,
                'not_found',
                $code === ''
                    ? 'No short code was provided.'
                    : sprintf('No short link is registered for code "%s".', $code)
            );
        }

        return Response::html($this->view->renderNotFound($code, '/dashboard'), 404)
            ->withSecurityHeaders(true);
    }
}
