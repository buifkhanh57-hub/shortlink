<?php

declare(strict_types=1);

namespace Shortlink\Controller;

use Shortlink\CodeGenerator;
use Shortlink\Exception\ConflictException;
use Shortlink\Exception\RateLimitException;
use Shortlink\Http\Request;
use Shortlink\Http\Response;
use Shortlink\RateLimiter;
use Shortlink\Storage\LinkRepository;
use Shortlink\Validator;

/**
 * POST /api/links — create a short link.
 *
 * Request body (JSON or form-encoded):
 *   url         string  required, http/https target
 *   alias       string  optional custom code (3-32 chars, [A-Za-z0-9_-])
 *   expires_at  string  optional ISO-8601 timestamp in the future
 *   max_clicks  int     optional click limit (1 .. 100000000)
 *   title       string  optional label, defaults to the target hostname
 *
 * Responses:
 *   201 Created              {"link": {...}, "rate_limit": {...}}
 *   409 Conflict             alias already taken
 *   422 Unprocessable Entity validation failed (per-field details)
 *   429 Too Many Requests    rate limit exhausted (Retry-After header)
 */
final class ShortenController
{
    public function __construct(
        private LinkRepository $links,
        private RateLimiter $limiter,
        private Validator $validator,
        private string $baseUrl,
        private int $codeLength = 7,
        private int $rateMax = 10,
        private int $rateWindowSeconds = 60,
        private array $trustedProxies = []
    ) {
    }

    public function create(Request $request): Response
    {
        $ip = $request->getClientIp($this->trustedProxies);
        $limit = $this->limiter->hit('create|' . $ip, $this->rateMax, $this->rateWindowSeconds);
        if (!$limit['allowed']) {
            throw new RateLimitException(
                sprintf('Too many links created from this address. Retry in %d seconds.', $limit['retry_after']),
                $limit['retry_after']
            );
        }

        $body = $request->getJsonBody();
        $target = $this->validator->validateTargetUrl((string)($body['url'] ?? ''));

        $custom = isset($body['alias']) && is_string($body['alias']) && trim($body['alias']) !== '';
        $alias = null;
        if ($custom) {
            $alias = $this->validator->validateAlias((string)$body['alias']);
            if ($this->links->exists($alias)) {
                throw new ConflictException(sprintf('Custom alias "%s" is already in use.', $alias));
            }
        }

        $expiresAt = $this->validator->validateExpiry($body['expires_at'] ?? null);
        $maxClicks = $this->validator->validateMaxClicks($body['max_clicks'] ?? null);
        $title = Validator::sanitizeText($body['title'] ?? '', 120);

        $code = $alias ?? CodeGenerator::unique(
            fn (string $candidate): bool => $this->links->exists($candidate),
            $this->codeLength
        );

        $record = $this->links->create($code, $target['url'], [
            'title' => $title !== '' ? $title : $target['host'],
            'custom' => $custom,
            'expires_at' => $expiresAt,
            'max_clicks' => $maxClicks,
        ]);

        return Response::json([
            'link' => $this->links->present($record, $this->baseUrl),
            'rate_limit' => [
                'limit' => $limit['limit'],
                'remaining' => $limit['remaining'],
                'reset_at' => $limit['reset_at'],
            ],
        ], 201);
    }
}
