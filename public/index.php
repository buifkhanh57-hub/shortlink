<?php

declare(strict_types=1);

/**
 * Shortlink — front controller.
 *
 * Every HTTP request enters through this file. The script wires the
 * configuration, the storage layer and the controllers together, registers
 * the routes, dispatches the request and emits the response.
 *
 * Run with the PHP built-in server (development):
 *
 *   php -S 127.0.0.1:8080 -t public public/index.php
 *
 * Static files under /assets/ are served directly: when the requested path
 * maps to an existing file the script returns false, which tells the
 * built-in server to stream the file itself. Under Apache the same job is
 * done by public/.htaccess; under nginx the config from the README applies.
 */

use Shortlink\ClickTracker;
use Shortlink\Controller\AdminController;
use Shortlink\Controller\DashboardController;
use Shortlink\Controller\RedirectController;
use Shortlink\Controller\ShortenController;
use Shortlink\Controller\StatsController;
use Shortlink\Exception\ConflictException;
use Shortlink\Exception\NotFoundException;
use Shortlink\Exception\RateLimitException;
use Shortlink\Exception\ShortlinkException;
use Shortlink\Exception\ValidationException;
use Shortlink\Http\Request;
use Shortlink\Http\Response;
use Shortlink\RateLimiter;
use Shortlink\Router;
use Shortlink\Storage\ClickRepository;
use Shortlink\Storage\JsonStore;
use Shortlink\Storage\LinkRepository;
use Shortlink\Validator;
use Shortlink\View\DashboardHtml;

$baseDir = dirname(__DIR__);

// ---- PSR-4 style autoloader (no Composer required) --------------------------
spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Shortlink\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $baseDir . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- configuration ----------------------------------------------------------
$config = require $baseDir . '/config/config.php';
if (!is_array($config)) {
    http_response_code(500);
    exit('Shortlink configuration is invalid.');
}

$appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
date_default_timezone_set((string)($appConfig['timezone'] ?? 'UTC'));
error_reporting(E_ALL);
ini_set('display_errors', ($appConfig['debug'] ?? false) === true ? '1' : '0');

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// ---- container --------------------------------------------------------------
$baseUrl = (string)($config['base_url'] ?? 'http://127.0.0.1:8080');
$storagePath = (string)($config['storage']['path'] ?? ($baseDir . '/data'));
$prettyJson = ($config['storage']['pretty_json'] ?? true) === true;

$makeStore = static fn (string $name): JsonStore => new JsonStore(
    rtrim($storagePath, '/') . '/' . $name . '.json',
    $prettyJson
);

$links = new LinkRepository($makeStore('links'));
$clicks = new ClickRepository($makeStore('clicks'), (int)($config['storage']['max_click_log_entries'] ?? 20000));
$limiter = new RateLimiter($makeStore('rate_limit'));

$linkConfig = is_array($config['links'] ?? null) ? $config['links'] : [];
$validator = new Validator(
    (int)($linkConfig['url_max_length'] ?? 2048),
    3,
    (int)($linkConfig['code_max_length'] ?? 32),
    (int)($linkConfig['max_expiry_days'] ?? 3650)
);

$security = is_array($config['security'] ?? null) ? $config['security'] : [];
$rateConfig = is_array($config['rate_limit'] ?? null) ? $config['rate_limit'] : [];
$dashboardConfig = is_array($config['dashboard'] ?? null) ? $config['dashboard'] : [];
$trustedProxies = is_array($security['trusted_proxies'] ?? null)
    ? array_values(array_map('strval', $security['trusted_proxies']))
    : [];

$appName = (string)($appConfig['name'] ?? 'Shortlink');
$tracker = new ClickTracker($clicks, (string)($security['ip_hash_salt'] ?? 'shortlink-dev-salt'));
$view = new DashboardHtml($baseUrl, $appName);

$shorten = new ShortenController(
    $links,
    $limiter,
    $validator,
    $baseUrl,
    (int)($linkConfig['code_length'] ?? 7),
    (int)($rateConfig['create_max'] ?? 10),
    (int)($rateConfig['create_window'] ?? 60),
    $trustedProxies
);
$redirect = new RedirectController(
    $links,
    $tracker,
    $limiter,
    $view,
    $baseUrl,
    $trustedProxies,
    (int)($rateConfig['redirect_max'] ?? 120),
    (int)($rateConfig['redirect_window'] ?? 60),
    ($rateConfig['enabled'] ?? true) === true
);
$stats = new StatsController($links, $clicks, $baseUrl);
$admin = new AdminController(
    $links,
    $clicks,
    $validator,
    $baseUrl,
    (string)($security['api_token'] ?? ''),
    ($security['admin_api'] ?? true) === true
);
$dashboard = new DashboardController($links, $clicks, $view, $baseUrl, $appName, $dashboardConfig);

// ---- routes -----------------------------------------------------------------
$router = new Router();
$router->get('/', [$dashboard, 'home']);
$router->get('/dashboard', [$dashboard, 'dashboard']);
$router->get('/health', [$dashboard, 'health']);
$router->post('/api/links', [$shorten, 'create']);
$router->get('/api/links', [$admin, 'listLinks']);
$router->get('/api/links/{code}', [$admin, 'show']);
$router->get('/api/links/{code}/stats', [$stats, 'stats']);
$router->patch('/api/links/{code}', [$admin, 'toggle']);
$router->delete('/api/links/{code}', [$admin, 'delete']);
$router->get('/{code}', [$redirect, 'redirect'], 'redirect');

$wantsJsonFor = static function (Request $request): bool {
    return $request->isApiPath() || $request->wantsJson();
};

$router->setNotFound(static function (Request $request) use ($view, $wantsJsonFor): Response {
    if ($wantsJsonFor($request)) {
        return Response::errorPayload(404, 'not_found', 'The requested resource does not exist.');
    }

    return Response::html($view->renderNotFound(ltrim($request->getPath(), '/'), '/dashboard'), 404)
        ->withSecurityHeaders(true);
});

$router->setMethodNotAllowed(static function (Request $request, array $allowed) use ($view, $wantsJsonFor): Response {
    if ($wantsJsonFor($request)) {
        return Response::errorPayload(
            405,
            'method_not_allowed',
            'Method not allowed for this resource.',
            ['allowed' => $allowed]
        )->withHeader('Allow', implode(', ', $allowed));
    }

    return Response::html(
        $view->renderErrorPage(405, 'Method not allowed', 'Allowed methods: ' . implode(', ', $allowed)),
        405
    )->withSecurityHeaders(true);
});

// ---- dispatch ---------------------------------------------------------------
$request = Request::fromGlobals();

// Hand real asset files to the built-in server (router-script convention).
if (str_starts_with($request->getPath(), '/assets/') && is_file(__DIR__ . $request->getPath())) {
    return false;
}

try {
    $response = $router->dispatch($request);
} catch (ValidationException $exception) {
    $response = Response::json($exception->toPayload(), $exception->getStatusCode());
} catch (RateLimitException $exception) {
    $response = Response::json($exception->toPayload(), $exception->getStatusCode())
        ->withHeader('Retry-After', (string)$exception->getRetryAfter());
} catch (ConflictException $exception) {
    $response = Response::json($exception->toPayload(), $exception->getStatusCode());
} catch (NotFoundException $exception) {
    if ($wantsJsonFor($request)) {
        $response = Response::json($exception->toPayload(), $exception->getStatusCode());
    } else {
        $response = Response::html($view->renderNotFound($request->getPath(), '/dashboard'), 404)
            ->withSecurityHeaders(true);
    }
} catch (ShortlinkException $exception) {
    $response = Response::json($exception->toPayload(), $exception->getStatusCode());
} catch (Throwable $unexpected) {
    error_log(sprintf(
        '[shortlink] uncaught %s: %s @ %s:%d',
        get_class($unexpected),
        $unexpected->getMessage(),
        $unexpected->getFile(),
        $unexpected->getLine()
    ));
    if ($wantsJsonFor($request)) {
        $response = Response::errorPayload(500, 'internal_error', 'An unexpected error occurred.');
    } else {
        $response = Response::html(
            $view->renderErrorPage(500, 'Something went wrong', 'An unexpected error occurred. Check the server logs.'),
            500
        )->withSecurityHeaders(true);
    }
}

$response->send();
