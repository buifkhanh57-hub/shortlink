<?php

declare(strict_types=1);

namespace Shortlink\Http;

/**
 * Value object describing an outgoing HTTP response.
 *
 * Factories cover the common cases (JSON, HTML, redirects, 204) and the
 * with*() methods return modified clones, keeping every instance immutable.
 * send() emits the response; it is only called by the front controller.
 */
final class Response
{
    private int $status;

    /** @var array<int, array{0: string, 1: string}> ordered header lines */
    private array $headers;

    private string $body;

    /**
     * @param array<string, string> $headers name => value map
     */
    public function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[] = [(string)$name, (string)$value];
        }
        $this->body = $body;
    }

    // ------------------------------------------------------------------
    // Factories
    // ------------------------------------------------------------------

    /**
     * JSON response. Encoding failures degrade into a 500 error payload
     * instead of emitting an empty body.
     *
     * @param array<string, string> $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = [], bool $pretty = false): self
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $body = json_encode($data, $flags);
        if ($body === false) {
            $status = 500;
            $body = '{"error":{"code":"encode_failed","message":"Response payload could not be encoded."}}';
        }

        return (new self($status, $headers, $body . "\n"))
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($status, [], $body))
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($status, [], $body))
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Redirect response with a tiny HTML fallback body for clients that
     * do not follow redirects automatically.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        $location = (string)preg_match('#^https?://#i', $location) === 1 ? $location : '/' . ltrim($location, '/');
        $fallback = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Redirecting…</title></head><body>'
            . '<p>Redirecting to <a href="' . htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>'
            . '</body></html>';

        return (new self($status, [], $fallback))
            ->withHeader('Location', $location)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withNoCache();
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /**
     * Consistent API error envelope.
     *
     * @param array<string, mixed> $extra additional keys inside "error"
     */
    public static function errorPayload(int $status, string $code, string $message, array $extra = []): self
    {
        $error = array_merge(['code' => $code, 'message' => $message], $extra);

        return self::json(['error' => $error], $status);
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<int, array{0: string, 1: string}> */
    public function getHeaderLines(): array
    {
        return $this->headers;
    }

    /** @return array<int, string> every value sent for the header name */
    public function getHeader(string $name): array
    {
        $lower = strtolower($name);
        $values = [];
        foreach ($this->headers as [$headerName, $value]) {
            if (strtolower($headerName) === $lower) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** All values of the header joined with ", " (empty string when absent). */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== [];
    }

    // ------------------------------------------------------------------
    // Fluent mutators (return clones)
    // ------------------------------------------------------------------

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    /** Replace every existing header with the same name. */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $lower = strtolower($name);
        $kept = [];
        foreach ($this->headers as $line) {
            if (strtolower($line[0]) !== $lower) {
                $kept[] = $line;
            }
        }
        $kept[] = [$name, $value];
        $clone->headers = $kept;

        return $clone;
    }

    /** Append an additional value for an existing header name. */
    public function withAddedHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[] = [$name, $value];

        return $clone;
    }

    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        $lower = strtolower($name);
        $kept = [];
        foreach ($this->headers as $line) {
            if (strtolower($line[0]) !== $lower) {
                $kept[] = $line;
            }
        }
        $clone->headers = $kept;

        return $clone;
    }

    /** Forbid any caching of the response (used on redirects and errors). */
    public function withNoCache(): self
    {
        return $this
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withAddedHeader('Pragma', 'no-cache')
            ->withAddedHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
    }

    /**
     * Add conservative security headers. HTML pages get a strict
     * Content-Security-Policy because the dashboard ships no JavaScript.
     */
    public function withSecurityHeaders(bool $isHtml = false): self
    {
        $response = $this
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($isHtml) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
            );
        }

        return $response;
    }

    // ------------------------------------------------------------------
    // Emission
    // ------------------------------------------------------------------

    /**
     * Send the response to the current output channel. In CLI/test runs
     * (headers already sent or header() unavailable) it degrades to
     * printing the body only, so tests can inspect getBody() instead.
     */
    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;

            return;
        }
        http_response_code($this->status);
        foreach ($this->headers as [$name, $value]) {
            header($name . ': ' . $value, false);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
            return;
        }
        echo $this->body;
    }

    /** Human readable reason phrase for common status codes. */
    public static function statusText(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            410 => 'Gone',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'HTTP ' . $status,
        };
    }
}
