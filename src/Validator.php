<?php

declare(strict_types=1);

namespace Shortlink;

use DateTimeImmutable;
use DateTimeZone;
use Shortlink\Exception\ValidationException;

/**
 * Input validation for the shortener API.
 *
 * Every validate*() method either returns the sanitized value or throws a
 * ValidationException carrying one human readable message per field, so the
 * front controller can turn it into a structured 422 response directly.
 */
final class Validator
{
    public const ALLOWED_SCHEMES = ['http', 'https'];

    public const STATUS_FILTERS = ['all', 'active', 'expired', 'exhausted', 'disabled'];

    public const SORT_FIELDS = ['newest', 'oldest', 'most_clicks', 'code'];

    public function __construct(
        private int $urlMaxLength = 2048,
        private int $aliasMinLength = 3,
        private int $aliasMaxLength = 32,
        private int $maxExpiryDays = 3650,
        private int $maxClicksLimit = 100000000
    ) {
    }

    /**
     * Validate and normalize a target URL.
     *
     * @return array{url: string, host: string, scheme: string}
     */
    public function validateTargetUrl(string $url): array
    {
        $errors = self::urlErrors($url, $this->urlMaxLength);
        if ($errors !== []) {
            throw new ValidationException('The submitted URL is not valid.', ['url' => implode(' ', $errors)]);
        }
        $normalized = self::normalizeUrl($url);
        $parts = parse_url($normalized);

        return [
            'url' => $normalized,
            'host' => strtolower((string)($parts['host'] ?? '')),
            'scheme' => strtolower((string)($parts['scheme'] ?? '')),
        ];
    }

    /**
     * Pure syntax/scheme check used by tests and the CLI.
     *
     * @return array<int, string> human readable problems, empty when valid
     */
    public static function urlErrors(string $url, int $maxLength = 2048): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['A target URL is required.'];
        }
        if (strlen($url) > $maxLength) {
            return [sprintf('URL exceeds the maximum length of %d characters.', $maxLength)];
        }
        $errors = [];
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            $errors[] = 'URL must not contain control characters or line breaks.';
        }
        if (str_contains($url, ' ')) {
            $errors[] = 'URL must not contain unencoded spaces.';
        }
        $parts = @parse_url($url);
        if ($parts === false) {
            $errors[] = 'URL could not be parsed.';

            return $errors;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme === '') {
            $errors[] = 'URL must be absolute and start with http:// or https://.';
        } elseif (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $errors[] = sprintf('URL scheme "%s:" is not allowed (only http and https).', $scheme);
        }
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            $errors[] = 'URL is missing a hostname.';
        } else {
            if (strlen($host) > 253) {
                $errors[] = 'Hostname is too long (maximum 253 characters).';
            }
            if (!self::isValidHost($host)) {
                $errors[] = sprintf('Hostname "%s" is not valid.', $host);
            }
        }
        if (isset($parts['port'])) {
            $port = (int)$parts['port'];
            if ($port < 1 || $port > 65535) {
                $errors[] = 'Port must be between 1 and 65535.';
            }
        }
        if (isset($parts['user']) && strlen((string)$parts['user']) > 64) {
            $errors[] = 'Username part of the URL is too long.';
        }

        return $errors;
    }

    /**
     * Hostname check: DNS labels, IPv4 literals and bracketed IPv6
     * literals plus the special name "localhost".
     */
    public static function isValidHost(string $host): bool
    {
        if (strtolower($host) === 'localhost') {
            return true;
        }
        if (preg_match('/^\[[0-9a-fA-F:.]{2,45}\]$/', $host) === 1) {
            return true;
        }
        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $host, $m) === 1) {
            for ($i = 1; $i <= 4; $i++) {
                if ((int)$m[$i] > 255) {
                    return false;
                }
            }

            return true;
        }

        return preg_match(
            '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/',
            $host
        ) === 1;
    }

    /**
     * Canonicalize a URL: lowercase scheme and host, drop default ports,
     * ensure a path is present. Query string and fragment are preserved.
     */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return trim($url);
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        $auth = '';
        if (isset($parts['user'])) {
            $auth = rawurlencode((string)$parts['user']);
            if (isset($parts['pass'])) {
                $auth .= ':' . rawurlencode((string)$parts['pass']);
            }
            $auth .= '@';
        }
        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) ? '?' . (string)$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . (string)$parts['fragment'] : '';
        $portPart = $port !== null ? ':' . $port : '';

        return $scheme . '://' . $auth . $host . $portPart . $path . $query . $fragment;
    }

    /**
     * Validate a client-chosen alias, returning it unchanged when valid.
     */
    public function validateAlias(string $alias): string
    {
        $alias = trim($alias);
        if (strlen($alias) < $this->aliasMinLength || strlen($alias) > $this->aliasMaxLength) {
            throw new ValidationException('The custom alias is not valid.', ['alias' => sprintf(
                'Alias length must be between %d and %d characters.',
                $this->aliasMinLength,
                $this->aliasMaxLength
            )]);
        }
        $problem = CodeGenerator::aliasProblem($alias);
        if ($problem !== null) {
            throw new ValidationException('The custom alias is not valid.', ['alias' => $problem]);
        }

        return $alias;
    }

    /**
     * Validate expires_at. Accepts ISO-8601 strings, plain dates and unix
     * timestamps; returns a normalized DATE_ATOM string in UTC or null.
     */
    public function validateExpiry(mixed $expiresAt): ?string
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }
        if (is_string($expiresAt) && strtolower(trim($expiresAt)) === 'null') {
            return null;
        }
        $parsed = false;
        if (is_int($expiresAt)) {
            try {
                $parsed = new DateTimeImmutable('@' . $expiresAt);
            } catch (\Exception) {
                $parsed = false;
            }
        } elseif (is_string($expiresAt)) {
            $raw = trim($expiresAt);
            foreach ([DATE_ATOM, 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
                $candidate = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone('UTC'));
                if ($candidate instanceof DateTimeImmutable) {
                    $lastErrors = DateTimeImmutable::getLastErrors();
                    $hasErrors = is_array($lastErrors)
                        && (((int)($lastErrors['warning_count'] ?? 0)) > 0 || ((int)($lastErrors['error_count'] ?? 0)) > 0);
                    if (!$hasErrors) {
                        $parsed = $candidate;
                        break;
                    }
                }
            }
        } else {
            throw new ValidationException('expires_at has an unsupported type.', [
                'expires_at' => 'Expected an ISO-8601 date string or a unix timestamp.',
            ]);
        }
        if ($parsed === false) {
            throw new ValidationException('expires_at could not be parsed.', [
                'expires_at' => 'Use an ISO-8601 date, e.g. 2026-12-31T23:59:59+00:00.',
            ]);
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($parsed <= $now) {
            throw new ValidationException('expires_at must be in the future.', [
                'expires_at' => 'Expiry must be later than the current time.',
            ]);
        }
        $limit = $now->modify('+' . $this->maxExpiryDays . ' days');
        if ($parsed > $limit) {
            throw new ValidationException('expires_at is too far in the future.', [
                'expires_at' => sprintf('Expiry cannot exceed %d days from now.', $this->maxExpiryDays),
            ]);
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    /**
     * Validate the optional click limit (null = unlimited).
     */
    public function validateMaxClicks(mixed $maxClicks): ?int
    {
        if ($maxClicks === null || $maxClicks === '') {
            return null;
        }
        $rangeMessage = sprintf('Expected an integer between 1 and %d.', $this->maxClicksLimit);
        if (is_bool($maxClicks) || is_array($maxClicks) || (!is_int($maxClicks) && !is_string($maxClicks))) {
            throw new ValidationException('max_clicks must be a positive integer.', ['max_clicks' => $rangeMessage]);
        }
        $raw = is_string($maxClicks) ? trim($maxClicks) : (string)$maxClicks;
        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new ValidationException('max_clicks must be a positive integer.', ['max_clicks' => $rangeMessage]);
        }
        $value = (int)$raw;
        if ($value < 1 || $value > $this->maxClicksLimit) {
            throw new ValidationException('max_clicks is out of range.', ['max_clicks' => sprintf(
                'Allowed range is 1 to %d.',
                $this->maxClicksLimit
            )]);
        }

        return $value;
    }

    /**
     * Validate pagination parameters. Non-numeric input falls back to the
     * default instead of failing; out-of-range values are rejected.
     *
     * @return array{page: int, per_page: int}
     */
    public function validatePagination(mixed $page, mixed $perPage): array
    {
        $resolvedPage = self::toNonNegativeInt($page) ?? 1;
        $resolvedPerPage = self::toNonNegativeInt($perPage) ?? 20;
        $errors = [];
        if ($resolvedPage < 1) {
            $errors['page'] = 'page must be 1 or greater.';
        }
        if ($resolvedPerPage < 1) {
            $errors['per_page'] = 'per_page must be 1 or greater.';
        } elseif ($resolvedPerPage > 100) {
            $errors['per_page'] = 'per_page is capped at 100.';
        }
        if ($errors !== []) {
            throw new ValidationException('Pagination parameters are invalid.', $errors);
        }

        return ['page' => $resolvedPage, 'per_page' => $resolvedPerPage];
    }

    /**
     * Whitelist check for the status filter of the admin list endpoint.
     */
    public function validateStatusFilter(mixed $status): string
    {
        $value = strtolower(trim((string)($status ?? 'all')));
        if ($value === '') {
            return 'all';
        }
        if (!in_array($value, self::STATUS_FILTERS, true)) {
            throw new ValidationException('Unknown status filter.', [
                'status' => 'Allowed values: ' . implode(', ', self::STATUS_FILTERS) . '.',
            ]);
        }

        return $value;
    }

    /**
     * Whitelist check for the sort field of the admin list endpoint.
     */
    public function validateSort(mixed $sort): string
    {
        $value = strtolower(trim((string)($sort ?? 'newest')));
        if ($value === '') {
            return 'newest';
        }
        if (!in_array($value, self::SORT_FIELDS, true)) {
            throw new ValidationException('Unknown sort field.', [
                'sort' => 'Allowed values: ' . implode(', ', self::SORT_FIELDS) . '.',
            ]);
        }

        return $value;
    }

    /**
     * Strip tags/control characters and cap the length of free-form text
     * (titles, search terms).
     */
    public static function sanitizeText(mixed $raw, int $maxLength = 120): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $clean = trim(strip_tags($raw));
        $clean = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $clean);
        if (strlen($clean) > $maxLength) {
            $clean = substr($clean, 0, $maxLength);
        }

        return trim($clean);
    }

    private static function toNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int)trim($value);
        }

        return null;
    }
}
