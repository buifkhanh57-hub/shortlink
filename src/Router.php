<?php

declare(strict_types=1);

namespace Shortlink;

use InvalidArgumentException;
use Shortlink\Http\Request;
use Shortlink\Http\Response;

/**
 * Minimal but complete pattern router.
 *
 * Features:
 *  - static routes ("/health") and dynamic routes ("/api/links/{code}/stats"),
 *  - optional per-parameter constraints ("/{id:\d+}"),
 *  - 404 fallback plus 405 with a correct "Allow" header,
 *  - HEAD requests transparently served by GET handlers (empty body),
 *  - named routes with pathFor() to build URLs in one place.
 */
final class Router
{
    public const SUPPORTED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** @var array<string, array<string, callable>> method => path => handler */
    private array $static = [];

    /** @var array<string, array<int, array{regex: string, names: array<int, string>, handler: callable, pattern: string}>> */
    private array $dynamic = [];

    /** @var array<string, array{0: string, 1: string}> name => [method, pattern] */
    private array $named = [];

    /** @var callable|null fn(Request): Response */
    private $notFound = null;

    /** @var callable|null fn(Request, string[] $allowed): Response */
    private $methodNotAllowed = null;

    /**
     * Register a route for one or more HTTP methods.
     *
     * @param array<int, string> $methods
     */
    public function map(array $methods, string $pattern, callable $handler, ?string $name = null): void
    {
        $normalized = [];
        foreach ($methods as $method) {
            $method = strtoupper(trim((string)$method));
            if ($method === '') {
                continue;
            }
            if (!in_array($method, self::SUPPORTED_METHODS, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported HTTP method "%s".', $method));
            }
            if (!in_array($method, $normalized, true)) {
                $normalized[] = $method;
            }
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('A route requires at least one HTTP method.');
        }
        $pattern = '/' . ltrim(trim($pattern), '/');
        foreach ($normalized as $method) {
            // HEAD is served by the GET handler (body stripped in dispatch()).
            $this->register($method === 'HEAD' ? 'GET' : $method, $pattern, $handler, $name);
        }
    }

    private function register(string $method, string $pattern, callable $handler, ?string $name): void
    {
        if (str_contains($pattern, '{')) {
            $compiled = self::compilePattern($pattern);
            $this->dynamic[$method][] = [
                'regex' => $compiled['regex'],
                'names' => $compiled['names'],
                'handler' => $handler,
                'pattern' => $pattern,
            ];
        } else {
            $this->static[$method][$pattern] = $handler;
        }
        if ($name !== null) {
            $this->named[$name] = [$method, $pattern];
        }
    }

    public function get(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->map(['GET', 'HEAD'], $pattern, $handler, $name);
    }

    public function post(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->map(['POST'], $pattern, $handler, $name);
    }

    public function put(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->map(['PUT'], $pattern, $handler, $name);
    }

    public function patch(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->map(['PATCH'], $pattern, $handler, $name);
    }

    public function delete(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->map(['DELETE'], $pattern, $handler, $name);
    }

    public function options(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->map(['OPTIONS'], $pattern, $handler, $name);
    }

    /** Override the default JSON 404 response. Signature: fn(Request): Response */
    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    /** Override the default JSON 405 response. Signature: fn(Request, string[]): Response */
    public function setMethodNotAllowed(callable $handler): void
    {
        $this->methodNotAllowed = $handler;
    }

    /**
     * Match a method/path pair without running the handler.
     *
     * @return array{status: 'found'|'not_found'|'method_not_allowed', handler?: callable, params?: array<string, string>, allowed?: array<int, string>}
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper(trim($method) === '' ? 'GET' : trim($method));
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        if (isset($this->static[$method][$path])) {
            return ['status' => 'found', 'handler' => $this->static[$method][$path], 'params' => []];
        }
        foreach ($this->dynamic[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                $params = [];
                foreach ($route['names'] as $name) {
                    $params[$name] = isset($matches[$name]) ? rawurldecode((string)$matches[$name]) : '';
                }

                return ['status' => 'found', 'handler' => $route['handler'], 'params' => $params];
            }
        }
        $allowed = $this->allowedMethodsFor($path);
        if ($allowed !== []) {
            return ['status' => 'method_not_allowed', 'allowed' => $allowed];
        }

        return ['status' => 'not_found'];
    }

    /**
     * Resolve the request to a Response by invoking the matching handler.
     */
    public function dispatch(Request $request): Response
    {
        $result = $this->match($request->getMethod(), $request->getPath());
        if ($result['status'] === 'found') {
            $handler = $result['handler'];
            $response = $handler($request, $result['params'] ?? []);
            if (!$response instanceof Response) {
                $response = Response::json($response);
            }
            if ($request->getMethod() === 'HEAD') {
                $response = $response->withBody('');
            }

            return $response;
        }
        if ($result['status'] === 'method_not_allowed') {
            $allowed = $result['allowed'] ?? [];
            if ($this->methodNotAllowed !== null) {
                return $this->methodNotAllowed($request, $allowed);
            }

            return Response::errorPayload(
                405,
                'method_not_allowed',
                'Method not allowed for this resource.',
                ['allowed' => $allowed]
            )->withHeader('Allow', implode(', ', $allowed));
        }
        if ($this->notFound !== null) {
            return $this->notFound($request);
        }

        return Response::errorPayload(404, 'not_found', 'The requested resource does not exist.');
    }

    /**
     * Build a URL for a named route with substituted parameters.
     *
     * @param array<string, string> $params
     */
    public function pathFor(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new InvalidArgumentException(sprintf('No named route "%s" is registered.', $name));
        }
        $pattern = $this->named[$name][1];
        $path = $pattern;
        foreach ($params as $key => $value) {
            $encoded = rawurlencode((string)$value);
            $path = str_replace('{' . $key . '}', $encoded, $path);
            $path = (string)preg_replace(
                '/\{' . preg_quote((string)$key, '/') . ':([^}]*)\}/',
                $encoded,
                $path
            );
        }

        return $path;
    }

    /**
     * Compile "{name}" / "{name:constraint}" segments into a regex plus the
     * ordered parameter name list.
     *
     * @return array{regex: string, names: array<int, string>}
     */
    public static function compilePattern(string $pattern): array
    {
        $names = [];
        $regex = '';
        $remaining = $pattern;
        $token = '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}#';
        while (preg_match($token, $remaining, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $prefix = substr($remaining, 0, (int)$matches[0][1]);
            $regex .= preg_quote($prefix, '#');
            $name = (string)$matches[1][0];
            $constraint = (string)($matches[2][0] ?? '');
            $names[] = $name;
            $regex .= '(?P<' . $name . '>' . ($constraint !== '' ? $constraint : '[^/]+') . ')';
            $remaining = substr($remaining, (int)$matches[0][1] + strlen((string)$matches[0][0]));
        }
        $regex .= preg_quote($remaining, '#');

        return ['regex' => '#^' . $regex . '$#', 'names' => $names];
    }

    /**
     * Human readable list of all registered routes, sorted, for the
     * console "info" command and debugging.
     *
     * @return array<int, string>
     */
    public function routes(): array
    {
        $lines = [];
        foreach ($this->static as $method => $byPath) {
            foreach (array_keys($byPath) as $path) {
                $lines[] = $method . '  ' . $path;
            }
        }
        foreach ($this->dynamic as $method => $routes) {
            foreach ($routes as $route) {
                $lines[] = $method . '  ' . $route['pattern'];
            }
        }
        sort($lines);

        return $lines;
    }

    /**
     * All methods registered for a path (used to build 405 responses).
     *
     * @return array<int, string>
     */
    private function allowedMethodsFor(string $path): array
    {
        $allowed = [];
        foreach (self::SUPPORTED_METHODS as $method) {
            $lookup = $method === 'HEAD' ? 'GET' : $method;
            if (isset($this->static[$lookup][$path])) {
                $allowed[] = $method;
                continue;
            }
            foreach ($this->dynamic[$lookup] ?? [] as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return array_values(array_unique($allowed));
    }
}
