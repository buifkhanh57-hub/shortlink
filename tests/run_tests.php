<?php

declare(strict_types=1);

/**
 * Shortlink test suite (no PHPUnit, no web server required).
 *
 * Run:  php tests/run_tests.php
 *
 * The suite exercises CodeGenerator, Validator, Router, the HTTP value
 * objects, the storage layer, the rate limiter, the user-agent parser and
 * one full request/response integration flow that simulates browser and
 * API traffic by constructing Request objects directly.
 */

use Shortlink\ClickTracker;
use Shortlink\CodeGenerator;
use Shortlink\Controller\AdminController;
use Shortlink\Controller\DashboardController;
use Shortlink\Controller\RedirectController;
use Shortlink\Controller\ShortenController;
use Shortlink\Controller\StatsController;
use Shortlink\Exception\ConflictException;
use Shortlink\Exception\NotFoundException;
use Shortlink\Exception\ShortlinkException;
use Shortlink\Exception\StorageException;
use Shortlink\Http\Request;
use Shortlink\Http\Response;
use Shortlink\RateLimiter;
use Shortlink\Router;
use Shortlink\Storage\ClickRepository;
use Shortlink\Storage\JsonStore;
use Shortlink\Storage\LinkRepository;
use Shortlink\Validator;
use Shortlink\View\DashboardHtml;

final class Harness
{
    public static int $passed = 0;

    /** @var array<int, string> */
    public static array $failed = [];

    public static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        printf("\n== %s ==\n", $name);
    }

    public static function check(string $name, bool $condition): void
    {
        if ($condition) {
            self::$passed++;
            echo '  [PASS] ' . $name . "\n";

            return;
        }
        self::$failed[] = self::$section . ' :: ' . $name;
        echo '  [FAIL] ' . $name . "\n";
    }

    public static function same(mixed $expected, mixed $actual, string $name): void
    {
        self::check($name, $expected === $actual);
        if ($expected !== $actual) {
            echo '    expected: ' . var_export($expected, true) . "\n";
            echo '    actual:   ' . var_export($actual, true) . "\n";
        }
    }

    public static function throws(callable $fn, string $exceptionClass, string $name): void
    {
        try {
            $fn();
            self::check($name, false);
            echo "    no exception was thrown\n";
        } catch (Throwable $thrown) {
            self::check($name, $thrown instanceof $exceptionClass);
            if (!$thrown instanceof $exceptionClass) {
                echo '    got: ' . get_class($thrown) . ' — ' . $thrown->getMessage() . "\n";
            }
        }
    }

    public static function summary(): int
    {
        printf("\n%d passed, %d failed\n", self::$passed, count(self::$failed));
        foreach (self::$failed as $failure) {
            echo '  FAILED: ' . $failure . "\n";
        }

        return self::$failed === [] ? 0 : 1;
    }
}

function newStoreDir(): string
{
    $dir = sys_get_temp_dir() . '/shortlink-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        fwrite(STDERR, "Cannot create test directory {$dir}\n");
        exit(1);
    }

    return $dir;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $entries = scandir($dir);
    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function makeRequest(string $method, string $path, array $jsonBody = [], array $headers = []): Request
{
    $body = $jsonBody === [] ? '' : (string)json_encode($jsonBody, JSON_UNESCAPED_SLASHES);

    return new Request($method, $path, [], $headers, [], [], $body);
}

// ===========================================================================
// Suites
// ===========================================================================

function suiteCodeGenerator(): void
{
    Harness::section('CodeGenerator: base62 round-trip and aliases');

    Harness::same('0', CodeGenerator::encode(0), 'encode(0) === "0"');
    Harness::same('Z', CodeGenerator::encode(61), 'encode(61) === "Z" (last alphabet char)');
    Harness::same('10', CodeGenerator::encode(62), 'encode(62) === "10"');
    Harness::same(62, CodeGenerator::decode('10'), 'decode("10") === 62');
    Harness::same(61, CodeGenerator::decode('Z'), 'decode("Z") === 61');
    Harness::same(3521614606208, CodeGenerator::capacity(7), 'capacity(7) === 62^7');

    $roundTripOk = true;
    for ($i = 0; $i < 500; $i++) {
        $number = random_int(0, 62 ** 6);
        if (CodeGenerator::decode(CodeGenerator::encode($number)) !== $number) {
            $roundTripOk = false;
            break;
        }
    }
    Harness::check('encode/decode round-trip for 500 random integers', $roundTripOk);

    Harness::throws(static fn () => CodeGenerator::encode(-1), InvalidArgumentException::class, 'encode(-1) throws');
    Harness::throws(static fn () => CodeGenerator::decode('abc!'), InvalidArgumentException::class, 'decode with invalid char throws');
    Harness::throws(static fn () => CodeGenerator::decode(''), InvalidArgumentException::class, 'decode("") throws');

    $random = CodeGenerator::random(7);
    Harness::check('random(7) has length 7', strlen($random) === 7);
    Harness::check('random(7) only uses base62 chars', preg_match('/^[0-9a-zA-Z]{7}$/', $random) === 1);

    $seen = [];
    for ($i = 0; $i < 50; $i++) {
        $seen[CodeGenerator::random(10)] = true;
    }
    Harness::check('50 random codes are distinct', count($seen) === 50);

    Harness::check('"api" is reserved', CodeGenerator::isReserved('API'));
    Harness::check('"dashboard" is reserved', CodeGenerator::isReserved('dashboard'));
    Harness::check('"health" is reserved', CodeGenerator::isReserved('health'));
    Harness::check('"cool-link" is not reserved', !CodeGenerator::isReserved('cool-link'));

    Harness::check('alias "my-shop-2026" is valid', CodeGenerator::isValidAlias('my-shop-2026'));
    Harness::check('alias "ab" is too short', !CodeGenerator::isValidAlias('ab'));
    Harness::check('alias "api" is reserved', !CodeGenerator::isValidAlias('api'));
    Harness::check('alias with slash is invalid', !CodeGenerator::isValidAlias('foo/bar'));
    Harness::check('alias starting with hyphen is invalid', !CodeGenerator::isValidAlias('-foo'));
    Harness::check('alias "a1" rejected (min length)', !CodeGenerator::isValidAlias('a1'));

    $calls = 0;
    $exists = static function (string $code) use (&$calls): bool {
        $calls++;

        return $calls <= 3;
    };
    $code = CodeGenerator::unique($exists, 7, 48);
    Harness::check('unique() retries until a free code is found', $calls >= 4);
    Harness::check('unique() returned a 7 char code', strlen($code) === 7);

    Harness::throws(
        static fn () => CodeGenerator::unique(static fn (string $c): bool => true, 7, 5),
        RuntimeException::class,
        'unique() throws when every candidate collides'
    );
}

function suiteValidator(): void
{
    Harness::section('Validator: URL, alias, expiry, pagination');

    $validator = new Validator();

    $ok = $validator->validateTargetUrl('https://example.com/some/page?q=1');
    Harness::same('https://example.com/some/page?q=1', $ok['url'], 'valid https URL passes');
    Harness::same('example.com', $ok['host'], 'host extracted');

    $normalized = $validator->validateTargetUrl('HTTP://EXAMPLE.COM:80/path');
    Harness::same('http://example.com/path', $normalized['url'], 'scheme/host lowercased, default port dropped');

    Harness::same('http://example.com/', Validator::normalizeUrl('http://example.com'), 'missing path becomes "/"');
    Harness::same('https://example.com/x#frag', Validator::normalizeUrl('https://EXAMPLE.com/x#frag'), 'fragment preserved');

    Harness::throws(
        static fn () => $validator->validateTargetUrl(''),
        Shortlink\Exception\ValidationException::class,
        'empty URL throws'
    );
    Harness::throws(
        static fn () => $validator->validateTargetUrl('example.com/page'),
        Shortlink\Exception\ValidationException::class,
        'URL without scheme throws'
    );
    Harness::throws(
        static fn () => $validator->validateTargetUrl('javascript:alert(1)'),
        Shortlink\Exception\ValidationException::class,
        'javascript: scheme rejected'
    );
    Harness::throws(
        static fn () => $validator->validateTargetUrl('ftp://files.example.com/x'),
        Shortlink\Exception\ValidationException::class,
        'ftp: scheme rejected'
    );
    Harness::throws(
        static fn () => $validator->validateTargetUrl('http://exa mple.com/x'),
        Shortlink\Exception\ValidationException::class,
        'space in host rejected'
    );
    Harness::throws(
        static fn () => $validator->validateTargetUrl('http://example.com:99999/'),
        Shortlink\Exception\ValidationException::class,
        'port out of range rejected'
    );
    Harness::throws(
        static fn () => $validator->validateTargetUrl('http:///nohost'),
        Shortlink\Exception\ValidationException::class,
        'missing host rejected'
    );
    Harness::check(
        'control-char URL reports problem',
        Validator::urlErrors("http://example.com/\n(x)") !== []
    );
    Harness::check(
        'over-long URL reports problem',
        Validator::urlErrors('http://example.com/' . str_repeat('a', 2100)) !== []
    );
    Harness::check('localhost accepted', Validator::urlErrors('http://localhost:8080/x') === []);
    Harness::check('IPv4 accepted', Validator::urlErrors('http://192.168.1.10/x') === []);
    Harness::check('invalid IPv4 octet rejected', Validator::urlErrors('http://300.1.1.1/') !== []);
    Harness::check('bracketed IPv6 accepted', Validator::urlErrors('http://[::1]:8080/x') === []);

    Harness::same('my-link', $validator->validateAlias('  my-link '), 'alias trimmed and returned');

    $future = gmdate('Y-m-d\TH:i:sP', time() + 86400);
    Harness::check('future expiry normalizes to DATE_ATOM', strlen((string)$validator->validateExpiry($future)) > 10);
    Harness::check('unix ts expiry accepted', $validator->validateExpiry(time() + 3600) !== null);
    Harness::check('null expiry passes through', $validator->validateExpiry(null) === null);
    Harness::throws(
        static fn () => $validator->validateExpiry('2020-01-01T00:00:00+00:00'),
        Shortlink\Exception\ValidationException::class,
        'past expiry rejected'
    );
    Harness::throws(
        static fn () => $validator->validateExpiry('not-a-date'),
        Shortlink\Exception\ValidationException::class,
        'garbage expiry rejected'
    );
    Harness::throws(
        static fn () => $validator->validateExpiry(gmdate('Y-m-d', time() + 400 * 86400)),
        Shortlink\Exception\ValidationException::class,
        'expiry beyond max days rejected'
    );

    Harness::same(5, $validator->validateMaxClicks('5'), 'numeric string max_clicks accepted');
    Harness::same(null, $validator->validateMaxClicks(''), 'empty max_clicks means unlimited');
    Harness::throws(
        static fn () => $validator->validateMaxClicks(0),
        Shortlink\Exception\ValidationException::class,
        'max_clicks=0 rejected'
    );
    Harness::throws(
        static fn () => $validator->validateMaxClicks(-3),
        Shortlink\Exception\ValidationException::class,
        'negative max_clicks rejected'
    );

    $pagination = $validator->validatePagination('3', '50');
    Harness::same(3, $pagination['page'], 'page parsed');
    Harness::same(50, $pagination['per_page'], 'per_page parsed');
    $defaults = $validator->validatePagination(null, null);
    Harness::same(1, $defaults['page'], 'page default is 1');
    Harness::same(20, $defaults['per_page'], 'per_page default is 20');
    Harness::throws(
        static fn () => $validator->validatePagination('0', '10'),
        Shortlink\Exception\ValidationException::class,
        'page=0 rejected'
    );
    Harness::throws(
        static fn () => $validator->validatePagination('1', '1000'),
        Shortlink\Exception\ValidationException::class,
        'per_page>100 rejected'
    );

    Harness::same('active', $validator->validateStatusFilter('ACTIVE'), 'status filter case-insensitive');
    Harness::throws(
        static fn () => $validator->validateStatusFilter('bogus'),
        Shortlink\Exception\ValidationException::class,
        'unknown status rejected'
    );
    Harness::same('most_clicks', $validator->validateSort('most_clicks'), 'sort whitelist ok');
    Harness::same('<b>bold</b>', Validator::sanitizeText(' <b>bold</b> ', 50), 'sanitizeText strips tags');
    Harness::same('abc', Validator::sanitizeText('abcdef', 3), 'sanitizeText caps length');
}

function suiteRouter(): void
{
    Harness::section('Router: static, patterns, 404/405, HEAD');

    $router = new Router();
    $router->get('/health', static function (Request $request): Response {
        return Response::text('healthy:' . $request->getMethod());
    });
    $router->get('/api/links/{code}/stats', static function (Request $request, array $params): Response {
        return Response::text('stats:' . $params['code']);
    }, 'stats-route');
    $router->get('/pages/{id:\d+}', static function (Request $request, array $params): Response {
        return Response::text('page:' . $params['id']);
    });
    $router->post('/api/links', static function (Request $request): Response {
        return Response::text('created');
    });

    $health = $router->match('GET', '/health');
    Harness::same('found', $health['status'], 'static route matches');
    $stats = $router->match('GET', '/api/links/abc123/stats');
    Harness::same('abc123', $stats['params']['code'] ?? null, 'dynamic parameter extracted');
    $page = $router->match('GET', '/pages/42');
    Harness::same('page:42', $router->dispatch(new Request('GET', '/pages/42'))->getBody(), 'constrained route dispatches');
    Harness::check('numeric constraint accepts digits', $page['status'] === 'found');
    Harness::same('not_found', $router->match('GET', '/pages/abc')['status'], 'non-digits fail the \\d+ constraint');

    Harness::same('not_found', $router->match('GET', '/nothing/here')['status'], 'unknown path is 404');
    $m405 = $router->match('GET', '/api/links');
    Harness::same('method_not_allowed', $m405['status'], 'wrong method is 405');
    Harness::check('405 lists allowed methods', in_array('POST', $m405['allowed'] ?? [], true));

    $response = $router->dispatch(new Request('GET', '/health'));
    Harness::same(200, $response->getStatusCode(), 'dispatch returns handler status');
    $response = $router->dispatch(new Request('POST', '/api/links'));
    Harness::same('created', $response->getBody(), 'POST dispatch reaches handler');

    $head = $router->dispatch(new Request('HEAD', '/health'));
    Harness::same(200, $head->getStatusCode(), 'HEAD falls back to GET handler');
    Harness::same('', $head->getBody(), 'HEAD response body is stripped');

    $missing = $router->dispatch(new Request('GET', '/definitely/missing'));
    Harness::same(404, $missing->getStatusCode(), 'default 404 payload status');

    Harness::same('/api/links/x/stats', $router->pathFor('stats-route', ['code' => 'x']), 'pathFor works');

    Harness::throws(
        static fn () => $router->pathFor('no-such-route'),
        InvalidArgumentException::class,
        'pathFor with unknown name throws'
    );
    Harness::throws(
        static fn () => $router->map(['TELEPORT'], '/x', static fn (): Response => Response::text('x')),
        InvalidArgumentException::class,
        'unsupported method rejected'
    );

    $routes = $router->routes();
    Harness::check('routes() lists every route', count($routes) >= 4);
}

function suiteHttp(): void
{
    Harness::section('Http: Request and Response');

    $backupServer = $_SERVER;
    $backupGet = $_GET;
    $backupCookie = $_COOKIE;
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/links?x=1&y=2',
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_USER_AGENT' => 'TestAgent/1.0',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        'CONTENT_TYPE' => 'application/json',
        'HTTPS' => 'off',
    ];
    $_GET = ['x' => '1', 'y' => '2'];
    $_COOKIE = ['sid' => 'abc'];

    $request = Request::fromGlobals();
    Harness::same('POST', $request->getMethod(), 'method from superglobals');
    Harness::same('/api/links', $request->getPath(), 'path without query string');
    Harness::same('1', $request->getQueryParam('x'), 'query param accessible');
    Harness::same('TestAgent/1.0', $request->getUserAgent(), 'user agent header');
    Harness::same('203.0.113.9', $request->getClientIp(), 'direct peer used when not trusted');
    Harness::same('198.51.100.7', $request->getClientIp(['203.0.113.9']), 'forwarded IP honored for trusted proxy');
    Harness::same('http', $request->getScheme(), 'scheme http when HTTPS off');
    Harness::check('api path wants JSON', $request->wantsJson());
    Harness::check('header lookup is case-insensitive', $request->hasHeader('Content-Type'));

    $_SERVER = $backupServer;
    $_GET = $backupGet;
    $_COOKIE = $backupCookie;

    $jsonRequest = new Request('POST', '/api/links', [], ['Content-Type' => 'application/json'], [], [], '{"url":"https://example.com","alias":"zzz"}');
    Harness::same('https://example.com', $jsonRequest->getJsonField('url'), 'JSON body decoded');
    Harness::same('zzz', $jsonRequest->getJsonField('alias'), 'JSON field access');

    $formRequest = new Request('POST', '/api/links', [], ['Content-Type' => 'application/x-www-form-urlencoded'], [], [], 'url=https%3A%2F%2Fexample.com&alias=abc');
    Harness::same('https://example.com', $formRequest->getFormBody()['url'] ?? null, 'form body decoded');

    Harness::same('GET', (new Request('get', 'x'))->getMethod(), 'method uppercased, empty becomes GET');
    Harness::same('/a/b', (new Request('GET', '/a/b/?'))->getPath(), 'trailing slash stripped');
    Harness::same('0.0.0.0', (new Request('GET', '/'))->getClientIp(), 'missing REMOTE_ADDR falls back');

    $json = Response::json(['ok' => true], 201);
    Harness::same(201, $json->getStatusCode(), 'json() keeps status');
    Harness::same("{'ok':true}", str_replace('"', "'", trim($json->getBody())), 'json() encodes body');
    Harness::check('json() sets content type', str_contains($json->getHeaderLine('Content-Type'), 'application/json'));

    $redirect = Response::redirect('https://example.com/target', 302);
    Harness::same('https://example.com/target', $redirect->getHeaderLine('Location'), 'redirect sets Location');
    Harness::check('redirect forbids caching', str_contains($redirect->getHeaderLine('Cache-Control'), 'no-store'));
    Harness::check('relative redirect gets leading slash', str_starts_with(Response::redirect('abc')->getHeaderLine('Location'), '/'));

    $secured = $json->withSecurityHeaders(false);
    Harness::same('nosniff', $secured->getHeaderLine('X-Content-Type-Options'), 'security headers added');
    $htmlSecured = Response::html('<p>hi</p>')->withSecurityHeaders(true);
    Harness::check('html gets CSP', str_contains($htmlSecured->getHeaderLine('Content-Security-Policy'), "default-src 'none'"));

    $clone = $json->withHeader('X-Test', '1')->withHeader('X-Test', '2');
    Harness::same('2', $clone->getHeaderLine('X-Test'), 'withHeader replaces previous value');
    Harness::check('original response immutable', $json->getHeaderLine('X-Test') === '');

    Harness::same('No Content', Response::statusText(204), 'statusText(204)');
    Harness::same('Too Many Requests', Response::statusText(429), 'statusText(429)');
    Harness::same('HTTP 599', Response::statusText(599), 'statusText fallback');
    Harness::same(204, Response::noContent()->getStatusCode(), 'noContent() status');
}

function suiteStorage(string $dir): void
{
    Harness::section('Storage: JsonStore, LinkRepository, ClickRepository');

    $store = new JsonStore($dir . '/doc.json');
    Harness::same([], $store->read(), 'missing document reads as []');
    $store->write(['a' => 1, 'nested' => ['x' => 'y']]);
    Harness::same(['a' => 1, 'nested' => ['x' => 'y']], $store->read(), 'write/read round-trip');
    $result = $store->mutate(static function (array $data): array {
        $data['b'] = ((int)($data['a'] ?? 0)) + 1;

        return $data;
    });
    Harness::same(2, $result['b'] ?? null, 'mutate applies callback');
    Harness::check('document file exists', $store->exists());
    Harness::check('lastModified > 0', $store->lastModified() > 0);

    file_put_contents($dir . '/doc.json', '{definitely not json');
    Harness::throws(static fn () => $store->read(), StorageException::class, 'corrupted JSON throws StorageException');

    $store->delete();
    Harness::same([], $store->read(), 'deleted document reads as []');
    Harness::check('backup of missing file is null', $store->backup() === null);

    $links = new LinkRepository(new JsonStore($dir . '/links.json'));
    $record = $links->create('abc1234', 'https://example.com/docs', ['max_clicks' => 2]);
    Harness::same('active', LinkRepository::computeStatus($record), 'fresh link is active');
    Harness::check('exists() sees new link', $links->exists('abc1234'));
    Harness::throws(
        static fn () => $links->create('abc1234', 'https://example.com/other'),
        ConflictException::class,
        'duplicate code raises ConflictException'
    );

    $record['clicks'] = 2;
    Harness::same('exhausted', LinkRepository::computeStatus($record), 'click limit reached → exhausted');
    $expiredRecord = $links->create('exp0001', 'https://example.com/old', ['expires_at' => gmdate(DATE_ATOM, time() - 3600)]);
    Harness::same('expired', LinkRepository::computeStatus($expiredRecord), 'past expiry → expired');
    $links->setEnabled('abc1234', false);
    Harness::same('disabled', LinkRepository::computeStatus($links->require('abc1234')), 'disabled overrides other states');

    $links->setEnabled('abc1234', true);
    Harness::same(1, $links->incrementClicks('abc1234'), 'incrementClicks returns new count');
    Harness::same(2, $links->incrementClicks('abc1234'), 'counter accumulates');
    Harness::same(2, $links->require('abc1234')['clicks'], 'record holds the counter');

    for ($i = 0; $i < 6; $i++) {
        $links->create('list' . $i, 'https://example.com/list' . $i, ['created_at' => gmdate(DATE_ATOM, time() - $i)]);
    }
    $page = $links->paginate(1, 3, 'all', '', 'newest');
    Harness::same(3, count($page['items']), 'pagination slices items');
    Harness::check('pagination total counts all', $page['total'] >= 8);
    $filtered = $links->paginate(1, 50, 'active', 'list3', 'code');
    Harness::same(1, $filtered['total'], 'search filter narrows to 1');
    Harness::same('list3', $filtered['items'][0]['code'] ?? '', 'search finds the right link');

    $summary = $links->statusSummary();
    Harness::check('summary totals add up', $summary['total'] === $summary['active'] + $summary['expired'] + $summary['disabled'] + $summary['exhausted']);

    $presented = $links->present($links->require('list0'), 'http://test.local');
    Harness::same('http://test.local/list0', $presented['short_url'], 'present() builds short_url');
    Harness::check('present() carries status', in_array($presented['status'], ['active', 'expired', 'exhausted', 'disabled'], true));

    Harness::throws(static fn () => $links->require('ghost99'), NotFoundException::class, 'require() throws NotFound');

    $clicks = new ClickRepository(new JsonStore($dir . '/clicks.json'), 100);
    $click = static fn (string $code, int $ts, string $hash, bool $bot = false): array => [
        'code' => $code,
        'ts' => $ts,
        'ip_hash' => $hash,
        'referrer' => 'google.com',
        'browser' => 'Chrome',
        'browser_version' => '120.0',
        'os' => 'Windows',
        'device' => 'Desktop',
        'is_bot' => $bot,
    ];
    $now = time();
    $stored = $clicks->append($click('codex01', $now, 'h1'));
    Harness::check('append assigns id 1', (int)($stored['id'] ?? 0) === 1);
    $clicks->append($click('codex01', $now, 'h1'));
    $clicks->append($click('codex01', $now, 'h2'));
    $clicks->append($click('codex02', $now, 'h3', true));
    Harness::same(3, $clicks->totalClicks('codex01'), 'totalClicks per code');
    Harness::same(2, $clicks->uniqueVisitors('codex01'), 'uniqueVisitors dedupes by hash');
    Harness::same(1, $clicks->botClicks('codex02'), 'bot clicks counted separately');

    $stats = $clicks->statsFor('codex01', 7);
    Harness::same(3, $stats['total_clicks'], 'statsFor totals');
    Harness::check('daily window has 7 buckets', count($stats['daily']) === 7);
    Harness::same(3, $stats['daily'][6]['clicks'] ?? 0, 'today bucket holds all clicks');
    Harness::same('google.com', $stats['referrers'][0]['referrer'] ?? '', 'referrer aggregated');
    Harness::check('recent clicks newest first', (int)($stats['recent'][0]['id'] ?? 0) >= (int)($stats['recent'][1]['id'] ?? 0));

    $top = $clicks->topCodes(5);
    Harness::same('codex01', $top[0]['code'] ?? '', 'topCodes ranks by clicks');
    $totals = $clicks->totals();
    Harness::same(4, $totals['total_clicks'], 'global totals count every click');
    Harness::same(2, $totals['unique_visitors'], 'global unique visitors (bots excluded)');

    $dailyGlobal = $clicks->dailyTotals(3);
    Harness::check('dailyTotals window length', count($dailyGlobal) === 3);
    Harness::same(4, $dailyGlobal[2]['clicks'] ?? 0, 'dailyTotals today');

    Harness::check('clearCode reports hit', $clicks->clearCode('codex02'));
    Harness::same(3, $clicks->totals()['total_clicks'], 'clearCode removes the code stats');
    Harness::same(0, $clicks->pruneOlderThan(30), 'nothing to prune for fresh data');
    Harness::check('exportLog returns rows', count($clicks->exportLog()) === 3);
}

function suiteRateLimiter(string $dir): void
{
    Harness::section('RateLimiter: fixed window with injected clock');

    $now = 1700000000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $limiter = new RateLimiter(new JsonStore($dir . '/rate.json'), $clock);

    $verdict = ['allowed' => false];
    for ($i = 0; $i < 5; $i++) {
        $verdict = $limiter->hit('ip|1', 5, 60);
    }
    Harness::check('five hits allowed at limit 5', $verdict['allowed']);
    Harness::same(0, $verdict['remaining'], 'no remaining attempts at the limit');

    $blocked = $limiter->hit('ip|1', 5, 60);
    Harness::check('sixth hit blocked', !$blocked['allowed']);
    Harness::check('retry_after between 1 and 60', $blocked['retry_after'] >= 1 && $blocked['retry_after'] <= 60);

    $peek = $limiter->peek('ip|1');
    Harness::same(6, $peek['count'] ?? 0, 'peek shows the raw counter');

    $other = $limiter->hit('ip|2', 5, 60);
    Harness::check('independent keys do not interfere', $other['allowed']);

    $now += 61;
    $afterWindow = $limiter->hit('ip|1', 5, 60);
    Harness::check('window reset reallows', $afterWindow['allowed']);

    $limiter->hit('ip|3', 5, 60);
    Harness::check('clear() removes a window', $limiter->clear('ip|3'));
    Harness::same(null, $limiter->peek('ip|3'), 'cleared window is gone');

    $now += 1000;
    Harness::check('prune() drops expired windows', $limiter->prune() >= 1);
    Harness::check('tooManyAttempts wrapper works', !$limiter->tooManyAttempts('k', 1, 60));
}

function suiteClickTracker(): void
{
    Harness::section('ClickTracker: UA parsing, referrers, IP hashing');

    $cases = [
        [
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'browser' => 'Chrome',
            'version' => '120.0.0.0',
            'os' => 'Windows 10/11',
            'device' => 'Desktop',
            'bot' => false,
        ],
        [
            'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
            'browser' => 'Safari',
            'version' => '17.4',
            'os' => 'macOS',
            'device' => 'Desktop',
            'bot' => false,
        ],
        [
            'ua' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'browser' => 'Firefox',
            'version' => '121.0',
            'os' => 'Linux',
            'device' => 'Desktop',
            'bot' => false,
        ],
        [
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.61',
            'browser' => 'Microsoft Edge',
            'version' => '120.0.2210.61',
            'os' => 'Windows 10/11',
            'device' => 'Desktop',
            'bot' => false,
        ],
        [
            'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            'browser' => 'Safari',
            'version' => '17.2',
            'os' => 'iOS',
            'device' => 'Mobile',
            'bot' => false,
        ],
        [
            'ua' => 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'browser' => 'Chrome',
            'version' => '120.0.0.0',
            'os' => 'Android 13',
            'device' => 'Mobile',
            'bot' => false,
        ],
        [
            'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'browser' => 'Googlebot',
            'version' => '',
            'os' => 'Unknown',
            'device' => 'bot',
            'bot' => true,
        ],
        [
            'ua' => 'curl/8.4.0',
            'browser' => 'curl',
            'version' => '',
            'os' => 'Unknown',
            'device' => 'bot',
            'bot' => true,
        ],
        [
            'ua' => 'python-requests/2.31.0',
            'browser' => 'Python Requests',
            'version' => '',
            'os' => 'Unknown',
            'device' => 'bot',
            'bot' => true,
        ],
        [
            'ua' => '',
            'browser' => 'Unknown',
            'version' => '',
            'os' => 'Unknown',
            'device' => 'Unknown',
            'bot' => false,
        ],
    ];

    foreach ($cases as $index => $case) {
        $parsed = ClickTracker::parseUserAgent($case['ua']);
        $label = 'case #' . $index . ' (' . substr($case['ua'], 0, 28) . '…)';
        Harness::same($case['browser'], $parsed['browser'], $label . ' browser');
        Harness::same($case['version'], $parsed['browser_version'], $label . ' version');
        Harness::same($case['os'], $parsed['os'], $label . ' os');
        Harness::same($case['device'], $parsed['device'], $label . ' device');
        Harness::same($case['bot'], $parsed['is_bot'], $label . ' bot flag');
    }

    Harness::same('direct', ClickTracker::classifyReferrer(''), 'empty referrer → direct');
    Harness::same('google.com', ClickTracker::classifyReferrer('https://www.google.com/search?q=x'), 'external referrer → host');
    Harness::same('internal', ClickTracker::classifyReferrer('https://short.ex/dashboard', 'short.ex'), 'self referrer → internal');
    Harness::same('internal', ClickTracker::classifyReferrer('https://short.ex/x', 'www.short.ex'), 'www mismatch normalized');
    Harness::same('invalid', ClickTracker::classifyReferrer('not a url'), 'garbage referrer → invalid');

    $tracker = new ClickTracker(new ClickRepository(new JsonStore(sys_get_temp_dir() . '/shortlink-hashes.json'), 100), 'salt-one');
    Harness::same($tracker->hashIp('203.0.113.5'), $tracker->hashIp('203.0.113.5'), 'same IP → same hash');
    Harness::check('different IP → different hash', $tracker->hashIp('203.0.113.5') !== $tracker->hashIp('198.51.100.5'));
    Harness::check('hash is 16 hex chars', preg_match('/^[0-9a-f]{16}$/', $tracker->hashIp('10.0.0.1')) === 1);
    Harness::check('salt changes the hash', (new ClickTracker(new ClickRepository(new JsonStore(sys_get_temp_dir() . '/shortlink-hashes.json'), 100), 'salt-two'))->hashIp('203.0.113.5') !== $tracker->hashIp('203.0.113.5'));

    $presented = ClickTracker::presentClick([
        'id' => 7,
        'code' => 'abc',
        'ts' => 1700000000,
        'ip_hash' => 'abcd1234abcd1234',
        'referrer' => 'google.com',
        'browser' => 'Chrome',
        'browser_version' => '120.0',
        'os' => 'Windows',
        'device' => 'Desktop',
        'is_bot' => false,
    ]);
    Harness::same('2023-11-14T22:13:20+00:00', $presented['clicked_at'], 'presentClick formats timestamp');
    Harness::same('Chrome 120.0', $presented['browser'], 'presentClick joins name+version');
    Harness::check('presentClick keeps hash as visitor', is_string($presented['visitor']));
}

function suiteIntegration(string $dir): void
{
    Harness::section('Integration: full request/response flow through the router');

    $links = new LinkRepository(new JsonStore($dir . '/i-links.json'));
    $clicks = new ClickRepository(new JsonStore($dir . '/i-clicks.json'), 100);
    $limiter = new RateLimiter(new JsonStore($dir . '/i-rate.json'));
    $validator = new Validator();
    $tracker = new ClickTracker($clicks, 'integration-salt');
    $view = new DashboardHtml('http://test.local', 'Shortlink');
    $shorten = new ShortenController($links, $limiter, $validator, 'http://test.local', 7, 1000, 60, []);
    $redirect = new RedirectController($links, $tracker, $limiter, $view, 'http://test.local', [], 1000, 60, false);
    $stats = new StatsController($links, $clicks, 'http://test.local');
    $admin = new AdminController($links, $clicks, $validator, 'http://test.local', 'secret-token', true);
    $dashboard = new DashboardController($links, $clicks, $view, 'http://test.local', 'Shortlink', []);

    $router = new Router();
    $router->get('/dashboard', [$dashboard, 'dashboard']);
    $router->get('/health', [$dashboard, 'health']);
    $router->post('/api/links', [$shorten, 'create']);
    $router->get('/api/links', [$admin, 'listLinks']);
    $router->get('/api/links/{code}', [$admin, 'show']);
    $router->get('/api/links/{code}/stats', [$stats, 'stats']);
    $router->patch('/api/links/{code}', [$admin, 'toggle']);
    $router->delete('/api/links/{code}', [$admin, 'delete']);
    $router->get('/{code}', [$redirect, 'redirect'], 'redirect');

    // --- create a link via POST /api/links
    $response = $router->dispatch(makeRequest('POST', '/api/links', [
        'url' => 'https://example.com/docs/getting-started?a=1',
        'title' => 'Getting started',
    ]));
    Harness::same(201, $response->getStatusCode(), 'create returns 201');
    $payload = json_decode(trim($response->getBody()), true);
    $code = (string)($payload['link']['code'] ?? '');
    Harness::check('create payload contains a code', strlen($code) === 7);
    Harness::same('http://test.local/' . $code, $payload['link']['short_url'] ?? '', 'short_url built from base url');
    Harness::same('Getting started', $payload['link']['title'] ?? '', 'title stored');

    // --- custom alias + conflict
    $response = $router->dispatch(makeRequest('POST', '/api/links', ['url' => 'https://example.com/promo', 'alias' => 'promo2026']));
    Harness::same(201, $response->getStatusCode(), 'custom alias accepted');
    Harness::throws(
        static fn () => $router->dispatch(makeRequest('POST', '/api/links', ['url' => 'https://example.com/x', 'alias' => 'promo2026'])),
        ConflictException::class,
        'second create with same alias conflicts'
    );
    Harness::throws(
        static fn () => $router->dispatch(makeRequest('POST', '/api/links', ['url' => 'notaurl'])),
        Shortlink\Exception\ValidationException::class,
        'invalid url rejected with 422 exception'
    );

    // --- redirect and click tracking
    $browserHeaders = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Referer' => 'https://www.google.com/search?q=docs',
    ];
    $response = $router->dispatch(new Request('GET', '/' . $code, [], $browserHeaders, [], ['REMOTE_ADDR' => '198.51.100.23']));
    Harness::same(302, $response->getStatusCode(), 'redirect answers 302');
    Harness::same('https://example.com/docs/getting-started?a=1', $response->getHeaderLine('Location'), 'redirect Location is the target');
    Harness::check('redirect is not cacheable', str_contains($response->getHeaderLine('Cache-Control'), 'no-store'));

    $unknown = $router->dispatch(new Request('GET', '/zzzzzzz'));
    Harness::same(404, $unknown->getStatusCode(), 'unknown code answers 404');
    Harness::check('404 page is HTML', str_contains($unknown->getBody(), 'Short link not found'));

    // --- stats reflect the click
    $response = $router->dispatch(makeRequest('GET', '/api/links/' . $code . '/stats'));
    Harness::same(200, $response->getStatusCode(), 'stats endpoint answers 200');
    $statsPayload = json_decode(trim($response->getBody()), true);
    Harness::same(1, (int)($statsPayload['totals']['clicks'] ?? 0), 'stats count exactly one click');
    Harness::same(1, (int)($statsPayload['totals']['unique_visitors'] ?? 0), 'stats count one unique visitor');
    Harness::same('google.com', $statsPayload['referrers'][0]['referrer'] ?? '', 'referrer recorded as hostname');
    Harness::same('Chrome', $statsPayload['recent_clicks'][0]['browser'] ?? '', 'browser recorded');
    Harness::check('ip hash stored, raw ip not', ($statsPayload['recent_clicks'][0]['visitor'] ?? '') !== '198.51.100.23');

    // --- admin endpoints require the token
    try {
        $unauthorized = $router->dispatch(makeRequest('GET', '/api/links'));
        Harness::same(401, $unauthorized->getStatusCode(), 'admin list without token → 401 response');
    } catch (ShortlinkException $exception) {
        Harness::same(401, $exception->getStatusCode(), 'admin list without token → 401 exception');
    }
    $authorized = $router->dispatch(makeRequest('GET', '/api/links?page=1&per_page=10', [], ['X-Api-Token' => 'secret-token']));
    Harness::same(200, $authorized->getStatusCode(), 'admin list with token → 200');
    $listPayload = json_decode(trim($authorized->getBody()), true);
    Harness::check('admin list contains both links', (int)($listPayload['pagination']['total'] ?? 0) === 2);

    // --- toggle
    $response = $router->dispatch(makeRequest('PATCH', '/api/links/promo2026', ['is_active' => false], ['X-Api-Token' => 'secret-token']));
    Harness::same(200, $response->getStatusCode(), 'toggle answers 200');
    Harness::same('disabled', json_decode(trim($response->getBody()), true)['link']['status'] ?? '', 'toggled link is disabled');
    $gone = $router->dispatch(new Request('GET', '/promo2026', [], $browserHeaders));
    Harness::same(410, $gone->getStatusCode(), 'disabled link redirects to 410');
    Harness::check('410 page explains', str_contains($gone->getBody(), 'disabled'));

    // --- delete
    $response = $router->dispatch(makeRequest('DELETE', '/api/links/promo2026', [], ['X-Api-Token' => 'secret-token']));
    Harness::same(204, $response->getStatusCode(), 'delete answers 204');
    $deleted = $router->dispatch(new Request('GET', '/promo2026', [], $browserHeaders));
    Harness::same(404, $deleted->getStatusCode(), 'deleted link answers 404');

    // --- dashboard + health render
    $response = $router->dispatch(makeRequest('GET', '/dashboard'));
    Harness::same(200, $response->getStatusCode(), 'dashboard answers 200');
    Harness::check('dashboard contains KPI cards', str_contains($response->getBody(), 'Total clicks'));
    Harness::check('dashboard has no script tags', !str_contains($response->getBody(), '<script'));

    $response = $router->dispatch(makeRequest('GET', '/health'));
    Harness::same(200, $response->getStatusCode(), 'health answers 200 when storage writable');
    Harness::same('ok', json_decode(trim($response->getBody()), true)['status'] ?? '', 'health reports ok');
}

// ===========================================================================
// Runner
// ===========================================================================

$dir = newStoreDir();
try {
    suiteCodeGenerator();
    suiteValidator();
    suiteRouter();
    suiteHttp();
    suiteStorage($dir);
    suiteRateLimiter($dir);
    suiteClickTracker();
    suiteIntegration($dir);
} finally {
    rrmdir($dir);
    @unlink(sys_get_temp_dir() . '/shortlink-hashes.json');
    @unlink(sys_get_temp_dir() . '/shortlink-hashes.json.lock');
}

exit(Harness::summary());
