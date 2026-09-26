<?php

declare(strict_types=1);

namespace Shortlink\Http;

use Shortlink\Exception\ShortlinkException;

/**
 * Immutable value object describing an incoming HTTP request.
 *
 * The object can be built from the PHP superglobals via fromGlobals() or
 * constructed directly from plain values, which keeps the router, the
 * controllers and the whole test suite free of any web-server dependency.
 */
final class Request
{
    private string $method;

    private string $path;

    private string $queryString;

    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, string> lowercase header name => value */
    private array $headers;

    /** @var array<string, string> */
    private array $cookies;

    /** @var array<string, mixed> */
    private array $server;

    private string $body;

    private ?array $jsonCache = null;

    private ?array $formCache = null;

    /**
     * @param array<string, mixed> $query    parsed query parameters
     * @param array<string, string> $headers raw headers (any casing)
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server   subset of $_SERVER
     */
    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        array $query = [],
        array $headers = [],
        array $cookies = [],
        array $server = [],
        string $body = ''
    ) {
        $this->method = strtoupper(trim($method) === '' ? 'GET' : trim($method));
        $parsed = parse_url($uri);
        $path = '/';
        $rawQuery = '';
        if (is_array($parsed)) {
            $path = (string)($parsed['path'] ?? '/');
            $rawQuery = (string)($parsed['query'] ?? '');
        } else {
            $path = $uri;
        }
        $path = '/' . ltrim(trim($path), '/');
        $this->path = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
        $this->queryString = $rawQuery;
        $this->query = $query;
        if ($this->query === [] && $rawQuery !== '') {
            parse_str($rawQuery, $parsedQuery);
            $this->query = $parsedQuery;
        }
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower(trim((string)$name))] = (string)$value;
        }
        $this->headers = $normalizedHeaders;
        $this->cookies = $cookies;
        $this->server = $server;
        $this->body = $body;
    }

    /**
     * Build a request from the PHP superglobals.
     */
    public static function fromGlobals(): self
    {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');

        $headers = [];
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                $headers = $all;
            }
        }
        if ($headers === []) {
            foreach ($_SERVER as $key => $value) {
                $key = (string)$key;
                if (str_starts_with($key, 'HTTP_')) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = (string)$value;
                }
            }
            if (isset($_SERVER['CONTENT_TYPE'])) {
                $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
            }
            if (isset($_SERVER['CONTENT_LENGTH'])) {
                $headers['content-length'] = (string)$_SERVER['CONTENT_LENGTH'];
            }
        }

        $body = '';
        $input = @file_get_contents('php://input');
        if (is_string($input)) {
            $body = $input;
        }

        return new self($method, $uri, is_array($_GET) ? $_GET : [], $headers, is_array($_COOKIE) ? $_COOKIE : [], $_SERVER, $body);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /** Normalized request path, always starting with "/", without query string. */
    public function getPath(): string
    {
        return $this->path;
    }

    public function getQueryString(): string
    {
        return $this->queryString;
    }

    /** @return array<string, mixed> */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function getQueryParam(string $name, ?string $default = null): ?string
    {
        $value = $this->query[$name] ?? null;
        if ($value === null || is_array($value)) {
            return $default;
        }

        return (string)$value;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    public function getHeader(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** @return array<string, string> */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getCookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return $value === null ? $default : (string)$value;
    }

    public function getServerParam(string $name, ?string $default = null): ?string
    {
        $value = $this->server[$name] ?? null;
        if ($value === null || is_array($value)) {
            return $default;
        }

        return (string)$value;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Decode the request body as JSON. Returns [] for an empty body and
     * falls back to form decoding when the payload is not valid JSON.
     *
     * @return array<string, mixed>
     */
    public function getJsonBody(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        $trimmed = ltrim($this->body);
        if ($trimmed === '') {
            $this->jsonCache = [];

            return $this->jsonCache;
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $this->jsonCache = $decoded;

            return $this->jsonCache;
        }
        $this->jsonCache = $this->getFormBody();

        return $this->jsonCache;
    }

    /**
     * Parse an application/x-www-form-urlencoded body.
     *
     * @return array<string, mixed>
     */
    public function getFormBody(): array
    {
        if ($this->formCache !== null) {
            return $this->formCache;
        }
        $contentType = strtolower($this->getHeader('content-type'));
        if (!str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $this->formCache = [];

            return $this->formCache;
        }
        $parsed = [];
        parse_str($this->body, $parsed);
        $this->formCache = $parsed;

        return $this->formCache;
    }

    public function getJsonField(string $name, mixed $default = null): mixed
    {
        $body = $this->getJsonBody();

        return $body[$name] ?? $default;
    }

    public function getUserAgent(): string
    {
        return $this->getHeader('user-agent');
    }

    public function getReferrer(): string
    {
        return $this->getHeader('referer');
    }

    /**
     * Resolve the client IP. Forwarded headers are only honored when the
     * direct peer (REMOTE_ADDR) is in the trusted proxy list, otherwise a
     * caller could spoof its address.
     *
     * @param array<int, string> $trustedProxies
     */
    public function getClientIp(array $trustedProxies = []): string
    {
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '');
        $remote = trim($remote);
        $remoteIsTrusted = in_array($remote, $trustedProxies, true);
        if ($remoteIsTrusted) {
            $forwarded = $this->getHeader('x-forwarded-for');
            if ($forwarded !== '') {
                $parts = array_map('trim', explode(',', $forwarded));
                $first = trim((string)($parts[0] ?? ''));
                if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
            $real = trim($this->getHeader('x-real-ip'));
            if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
                return $real;
            }
        }
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return $remote;
        }

        return '0.0.0.0';
    }

    public function getScheme(): string
    {
        $proto = strtolower($this->getHeader('x-forwarded-proto'));
        if ($proto === 'https') {
            return 'https';
        }
        $https = (string)($this->server['HTTPS'] ?? '');

        return ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
    }

    public function getHost(): string
    {
        $host = trim((string)($this->server['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string)($this->server['SERVER_NAME'] ?? ''));
        }
        if ($host === '') {
            return 'localhost';
        }
        // Strip the port part, keep IPv6 literals intact.
        if (str_starts_with($host, '[')) {
            return $host;
        }
        $parsed = parse_url('http://' . $host, PHP_URL_HOST);

        return strtolower(is_string($parsed) && $parsed !== '' ? $parsed : $host);
    }

    public function isSecure(): bool
    {
        return $this->getScheme() === 'https';
    }

    public function isApiPath(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    public function wantsJson(): bool
    {
        if ($this->isApiPath()) {
            return true;
        }
        $accept = strtolower($this->getHeader('accept'));

        return str_contains($accept, 'application/json');
    }

    public function isSecureConnection(): bool
    {
        return $this->isSecure();
    }

    /**
     * Reconstruct the absolute URL of the request (for logging/debugging).
     */
    public function fullUrl(): string
    {
        $url = $this->getScheme() . '://' . $this->getHost() . $this->path;
        if ($this->queryString !== '') {
            $url .= '?' . $this->queryString;
        }

        return $url;
    }

    /**
     * Compact representation used in tests and error logs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'headers' => $this->headers,
            'body_length' => strlen($this->body),
        ];
    }
}
