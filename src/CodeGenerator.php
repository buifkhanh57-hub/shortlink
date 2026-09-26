<?php

declare(strict_types=1);

namespace Shortlink;

use InvalidArgumentException;
use RuntimeException;

/**
 * Base62 code generation with custom alias validation and collision retry.
 *
 * The alphabet is ordered "0-9 a-z A-Z" so that numeric-only codes sort
 * naturally and encode()/decode() are exact inverses. Generated codes never
 * collide with reserved words (api, dashboard, health, …) because unique()
 * filters them before returning a candidate.
 */
final class CodeGenerator
{
    public const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public const ALPHABET_SIZE = 62;

    /**
     * Paths claimed by the application itself (routes, assets, common
     * crawler bait). Neither generated codes nor custom aliases may use
     * them, otherwise they would be shadowed by the "/{code}" route.
     */
    public const RESERVED = [
        'api',
        'dashboard',
        'health',
        'assets',
        'admin',
        'administrator',
        'static',
        'public',
        'login',
        'logout',
        'signin',
        'signup',
        'register',
        'password',
        'settings',
        'account',
        'profile',
        'help',
        'docs',
        'doc',
        'support',
        'contact',
        'about',
        'terms',
        'privacy',
        'status',
        'metrics',
        'search',
        'blog',
        'news',
        'app',
        'mobile',
        'link',
        'links',
        'url',
        'urls',
        'go',
        'new',
        'create',
        'delete',
        'edit',
        'stats',
        'analytics',
        'report',
        'export',
        'import',
        'user',
        'users',
        'me',
        'null',
        'undefined',
        'root',
        'wp-admin',
        'favicon.ico',
        'robots.txt',
        'sitemap.xml',
    ];

    /** Static-only class: no instances required. */
    private function __construct()
    {
    }

    /** Lazily built lowercase lookup table for reserved words. @return array<string, bool> */
    private static function reservedIndex(): array
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::RESERVED as $word) {
                $index[strtolower($word)] = true;
            }
        }

        return $index;
    }

    /**
     * Encode a non-negative integer as base62.
     *
     * @throws InvalidArgumentException for negative input
     */
    public static function encode(int $number): string
    {
        if ($number < 0) {
            throw new InvalidArgumentException('Base62 encoding requires a non-negative integer.');
        }
        if ($number === 0) {
            return '0';
        }
        $value = $number;
        $out = '';
        while ($value > 0) {
            $remainder = $value % self::ALPHABET_SIZE;
            $out = self::ALPHABET[$remainder] . $out;
            $value = intdiv($value, self::ALPHABET_SIZE);
        }

        return $out;
    }

    /**
     * Decode a base62 string back to its integer value (inverse of encode()).
     *
     * @throws InvalidArgumentException on empty input or invalid characters
     */
    public static function decode(string $value): int
    {
        if ($value === '') {
            throw new InvalidArgumentException('Cannot decode an empty base62 string.');
        }
        $result = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $position = strpos(self::ALPHABET, $value[$i]);
            if ($position === false) {
                throw new InvalidArgumentException(sprintf('Character "%s" is not valid base62.', $value[$i]));
            }
            $result = ($result * self::ALPHABET_SIZE) + $position;
        }

        return $result;
    }

    /**
     * Number of distinct codes of a given length (62^length).
     */
    public static function capacity(int $length): int
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Code length must be at least 1.');
        }

        return self::ALPHABET_SIZE ** $length;
    }

    /**
     * Cryptographically random code of exactly $length base62 characters.
     */
    public static function random(int $length = 7): string
    {
        if ($length < 1 || $length > 64) {
            throw new InvalidArgumentException('Code length must be between 1 and 64.');
        }
        $maxIndex = self::ALPHABET_SIZE - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        return $code;
    }

    /**
     * Generate a random code that is neither reserved nor already used.
     * $exists is consulted for every candidate (usually LinkRepository::exists()).
     *
     * @param callable(string): bool $exists
     * @throws RuntimeException when no free code is found within $maxAttempts
     */
    public static function unique(callable $exists, int $length = 7, int $maxAttempts = 48): string
    {
        $length = max(4, min(64, $length));
        $last = '';
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = self::random($length);
            $last = $code;
            if (!$exists($code) && !self::isReserved($code)) {
                return $code;
            }
        }
        throw new RuntimeException(sprintf(
            'Unable to generate a unique code after %d attempts (last candidate "%s").',
            $maxAttempts,
            $last
        ));
    }

    public static function isReserved(string $code): bool
    {
        return isset(self::reservedIndex()[strtolower($code)]);
    }

    /**
     * Validate a client-chosen alias and return the first problem found,
     * or null when the alias is acceptable.
     */
    public static function aliasProblem(string $alias): ?string
    {
        if ($alias === '') {
            return 'Custom alias is required when provided.';
        }
        if (strlen($alias) < 3) {
            return 'Custom alias must be at least 3 characters long.';
        }
        if (strlen($alias) > 32) {
            return 'Custom alias must be at most 32 characters long.';
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $alias) !== 1) {
            return 'Custom alias may only contain letters, digits, underscores and hyphens, '
                . 'and must start with a letter or digit.';
        }
        if (self::isReserved($alias)) {
            return sprintf('"%s" is a reserved word and cannot be used as an alias.', $alias);
        }

        return null;
    }

    public static function isValidAlias(string $alias): bool
    {
        return self::aliasProblem($alias) === null;
    }
}
