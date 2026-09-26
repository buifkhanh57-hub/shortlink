<?php

declare(strict_types=1);

/**
 * Shortlink configuration.
 *
 * Every value can be overridden through environment variables so the same
 * code base can run unchanged in development and production. The file is a
 * plain PHP script returning an array — no YAML/INI parsing required.
 *
 * Environment variables:
 *   SHORTLINK_BASE_URL      Public base URL used to build short links.
 *   SHORTLINK_STORAGE_PATH  Directory that holds the JSON documents.
 *   SHORTLINK_API_TOKEN     Shared secret for admin API endpoints.
 *   SHORTLINK_IP_SALT       Salt for hashing visitor IP addresses.
 *   SHORTLINK_DEBUG         "1" to display errors, "0" to log them only.
 *   SHORTLINK_ENV           Environment label, e.g. "production".
 */

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'app' => [
        'name' => 'Shortlink',
        'env' => getenv('SHORTLINK_ENV') ?: 'production',
        'debug' => (getenv('SHORTLINK_DEBUG') ?: '0') === '1',
        'timezone' => 'UTC',
    ],

    // Public origin of every generated short link, without trailing slash.
    'base_url' => rtrim((string)(getenv('SHORTLINK_BASE_URL') ?: 'http://127.0.0.1:8080'), '/'),

    'storage' => [
        // Directory for the JSON documents (links, clicks, rate limits).
        // Created automatically on first write.
        'path' => (string)(getenv('SHORTLINK_STORAGE_PATH') ?: ($root . '/data')),
        // Maximum number of raw click rows kept in the append-only log.
        // Aggregated daily statistics are unaffected by this cap.
        'max_click_log_entries' => 20000,
        // Pretty-print JSON documents on disk (human diffable, slightly slower).
        'pretty_json' => true,
    ],

    'links' => [
        // Length of randomly generated base62 codes.
        'code_length' => 7,
        // Maximum length of a custom alias.
        'code_max_length' => 32,
        // Maximum accepted length of a target URL.
        'url_max_length' => 2048,
        // Upper bound for expires_at (in days from creation time).
        'max_expiry_days' => 3650,
    ],

    'rate_limit' => [
        // Master switch. When disabled no limiter writes are performed.
        'enabled' => true,
        // POST /api/links: max requests per window per client IP.
        'create_max' => 10,
        'create_window' => 60,
        // GET /{code}: max redirects per window per client IP.
        'redirect_max' => 120,
        'redirect_window' => 60,
    ],

    'security' => [
        // Shared secret required by admin endpoints (list/delete/patch).
        // Send it as the "X-Api-Token" request header. When empty, admin
        // endpoints are open — only do this on a trusted private network.
        'api_token' => (string)(getenv('SHORTLINK_API_TOKEN') ?: 'change-me-local-token'),
        // When false, admin endpoints are open regardless of the token.
        'admin_api' => true,
        // Salt mixed into visitor IP hashes. Rotate to invalidate history.
        'ip_hash_salt' => (string)(getenv('SHORTLINK_IP_SALT') ?: 'shortlink-default-salt'),
        // IPs trusted to set X-Forwarded-For / X-Real-IP (reverse proxies).
        'trusted_proxies' => ['127.0.0.1', '::1'],
    ],

    'dashboard' => [
        'recent_links' => 10,
        'top_links' => 8,
        'recent_clicks' => 15,
        'chart_days' => 14,
    ],
];
