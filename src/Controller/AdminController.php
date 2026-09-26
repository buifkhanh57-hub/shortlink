<?php

declare(strict_types=1);

namespace Shortlink\Controller;

use Shortlink\Exception\ShortlinkException;
use Shortlink\Exception\ValidationException;
use Shortlink\Http\Request;
use Shortlink\Http\Response;
use Shortlink\Storage\ClickRepository;
use Shortlink\Storage\LinkRepository;
use Shortlink\Validator;

/**
 * Administrative API endpoints.
 *
 *   GET    /api/links          paginated list with filters (listLinks)
 *   GET    /api/links/{code}   single link (show)
 *   DELETE /api/links/{code}   delete link + click history (delete)
 *   PATCH  /api/links/{code}   toggle is_active (toggle)
 *
 * The mutating endpoints (DELETE, PATCH) and the full list require the
 * shared secret in the "X-Api-Token" header; single-link reads are public.
 * When the configured token is empty (dev setups) authorization is open.
 */
final class AdminController
{
    public function __construct(
        private LinkRepository $links,
        private ClickRepository $clicks,
        private Validator $validator,
        private string $baseUrl,
        private string $apiToken = '',
        private bool $adminEnabled = true
    ) {
    }

    /**
     * GET /api/links?page=1&per_page=20&status=active&search=docs&sort=newest
     */
    public function listLinks(Request $request): Response
    {
        $this->authorize($request);
        $pagination = $this->validator->validatePagination(
            $request->getQueryParam('page', '1'),
            $request->getQueryParam('per_page', '20')
        );
        $status = $this->validator->validateStatusFilter($request->getQueryParam('status', 'all'));
        $sort = $this->validator->validateSort($request->getQueryParam('sort', 'newest'));
        $search = Validator::sanitizeText($request->getQueryParam('search', ''), 200);

        $result = $this->links->paginate(
            $pagination['page'],
            $pagination['per_page'],
            $status,
            $search,
            $sort
        );

        $items = [];
        foreach ($result['items'] as $record) {
            if (is_array($record)) {
                $items[] = $this->links->present($record, $this->baseUrl);
            }
        }

        return Response::json([
            'data' => $items,
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'pages' => $result['pages'],
            ],
            'summary' => $this->links->statusSummary(),
        ]);
    }

    /** GET /api/links/{code} — single link, public read. */
    public function show(Request $request, array $params = []): Response
    {
        $code = (string)($params['code'] ?? '');
        $record = $this->links->require($code);

        return Response::json([
            'link' => $this->links->present($record, $this->baseUrl),
        ]);
    }

    /** DELETE /api/links/{code} — remove the link and its click history. */
    public function delete(Request $request, array $params = []): Response
    {
        $this->authorize($request);
        $code = (string)($params['code'] ?? '');
        $this->links->require($code);
        $this->links->delete($code);
        $this->clicks->clearCode($code);

        return Response::noContent();
    }

    /**
     * PATCH /api/links/{code} — toggle availability without losing stats.
     * Body: {"is_active": true|false}
     */
    public function toggle(Request $request, array $params = []): Response
    {
        $this->authorize($request);
        $code = (string)($params['code'] ?? '');
        $body = $request->getJsonBody();
        if (!array_key_exists('is_active', $body) || !is_bool($body['is_active'])) {
            throw new ValidationException(
                'The request body must contain a boolean "is_active" field.',
                ['is_active' => 'Expected true or false.']
            );
        }
        $record = $this->links->setEnabled($code, $body['is_active']);

        return Response::json([
            'link' => $this->links->present($record, $this->baseUrl),
        ]);
    }

    /**
     * Shared-secret check for admin endpoints.
     *
     * @throws ShortlinkException with status 401 on missing/invalid token
     */
    private function authorize(Request $request): void
    {
        if (!$this->adminEnabled) {
            return;
        }
        if ($this->apiToken === '') {
            return; // open in unconfigured/dev environments
        }
        $token = $request->getHeader('x-api-token');
        if ($token === '' || !hash_equals($this->apiToken, $token)) {
            throw new ShortlinkException(
                'A valid X-Api-Token header is required for admin endpoints.',
                0,
                null,
                401
            );
        }
    }
}
