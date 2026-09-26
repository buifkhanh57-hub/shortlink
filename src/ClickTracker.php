<?php

declare(strict_types=1);

namespace Shortlink;

use Shortlink\Http\Request;
use Shortlink\Storage\ClickRepository;

/**
 * Turns an incoming redirect request into a privacy-friendly click record.
 *
 * Privacy properties:
 *  - the raw IP address never touches disk; only a truncated SHA-256 hash
 *    keyed with a per-installation salt is stored,
 *  - referrers are reduced to their hostname (no path, no query string),
 *  - user agents are classified into browser / version / OS / device plus
 *    a bot flag; no UA string is persisted.
 */
final class ClickTracker
{
    /**
     * Detection patterns for crawlers, previewers and scripted clients.
     * Matched case-insensitively against the raw User-Agent header.
     */
    public const BOT_PATTERN =
        '/(bot|crawler|spider|slurp|crawl|curl|wget|python-requests|python-urllib|python-httpx'
        . '|go-http-client|java\/|apache-httpclient|libwww|httpclient|okhttp|scrapy'
        . '|headless|phantomjs|lighthouse|pingdom|uptimerobot|uptime|monitor|semrush'
        . '|ahrefs|mj12bot|dotbot|petalbot|bingpreview|facebookexternalhit|embedly'
        . '|quora link preview|outbrain|pinterest|slack-imgproxy|telegrambot|twitterbot'
        . '|whatsapp|discordapp|preview)/i';

    private ClickRepository $clicks;

    private string $ipSalt;

    public function __construct(ClickRepository $clicks, string $ipSalt)
    {
        $this->clicks = $clicks;
        $this->ipSalt = $ipSalt;
    }

    /**
     * Build a click record from the request and persist it.
     *
     * @param array<int, string> $trustedProxies
     * @return array<string, mixed> the stored record (including its id)
     */
    public function track(
        Request $request,
        string $code,
        ?int $timestamp = null,
        array $trustedProxies = []
    ): array {
        $ua = self::parseUserAgent($request->getUserAgent());
        $ip = $request->getClientIp($trustedProxies);
        $click = [
            'code' => $code,
            'ts' => $timestamp ?? time(),
            'ip_hash' => $this->hashIp($ip),
            'referrer' => self::classifyReferrer($request->getReferrer(), $request->getHost()),
            'browser' => $ua['browser'],
            'browser_version' => $ua['browser_version'],
            'os' => $ua['os'],
            'device' => $ua['device'],
            'is_bot' => $ua['is_bot'],
        ];

        return $this->clicks->append($click);
    }

    /**
     * Salted, truncated IP hash. The same visitor produces the same hash
     * within one installation (enables unique-visitor counts) but hashes
     * cannot be reversed and differ between installations with other salts.
     */
    public function hashIp(string $ip): string
    {
        return substr(hash('sha256', $ip . '|' . $this->ipSalt), 0, 16);
    }

    /**
     * Parse a User-Agent string into its components.
     *
     * @return array{browser: string, browser_version: string, os: string, device: string, is_bot: bool}
     */
    public static function parseUserAgent(string $userAgent): array
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return [
                'browser' => 'Unknown',
                'browser_version' => '',
                'os' => 'Unknown',
                'device' => 'Unknown',
                'is_bot' => false,
            ];
        }
        if (preg_match(self::BOT_PATTERN, $ua) === 1) {
            return [
                'browser' => self::botName($ua),
                'browser_version' => '',
                'os' => 'Unknown',
                'device' => 'bot',
                'is_bot' => true,
            ];
        }

        [$browser, $version] = self::detectBrowser($ua);

        return [
            'browser' => $browser,
            'browser_version' => $version,
            'os' => self::detectOs($ua),
            'device' => self::detectDevice($ua),
            'is_bot' => false,
        ];
    }

    /**
     * Reduce a referrer URL to a stable category:
     *   - "direct"    when the header is empty (typed / bookmark),
     *   - "invalid"   when it cannot be parsed,
     *   - "internal"  when it points at this installation itself,
     *   - otherwise the bare hostname ("google.com").
     */
    public static function classifyReferrer(string $referrer, string $selfHost = ''): string
    {
        $referrer = trim($referrer);
        if ($referrer === '') {
            return 'direct';
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return 'invalid';
        }
        $host = strtolower($host);
        $self = strtolower(trim($selfHost));
        $host = self::stripWww($host);
        $self = self::stripWww($self);
        if ($self !== '' && $host === $self) {
            return 'internal';
        }

        return $host;
    }

    /**
     * Public projection of a stored click for API/dashboard output.
     *
     * @param array<string, mixed> $click
     * @return array<string, mixed>
     */
    public static function presentClick(array $click): array
    {
        $ts = (int)($click['ts'] ?? 0);

        return [
            'id' => (int)($click['id'] ?? 0),
            'code' => (string)($click['code'] ?? ''),
            'clicked_at' => gmdate(DATE_ATOM, $ts),
            'visitor' => (string)($click['ip_hash'] ?? ''),
            'referrer' => (string)($click['referrer'] ?? ''),
            'browser' => trim((string)($click['browser'] ?? 'Unknown') . ' ' . (string)($click['browser_version'] ?? '')),
            'os' => (string)($click['os'] ?? 'Unknown'),
            'device' => (string)($click['device'] ?? 'Unknown'),
            'is_bot' => (bool)($click['is_bot'] ?? false),
        ];
    }

    /** Identify a known bot family from the UA, generic "Bot" otherwise. */
    private static function botName(string $ua): string
    {
        $known = [
            'googlebot' => 'Googlebot',
            'bingbot' => 'Bingbot',
            'yandex' => 'YandexBot',
            'duckduck' => 'DuckDuckBot',
            'baiduspider' => 'Baiduspider',
            'slurp' => 'Yahoo Slurp',
            'facebookexternalhit' => 'Facebook',
            'twitterbot' => 'Twitterbot',
            'telegrambot' => 'TelegramBot',
            'whatsapp' => 'WhatsApp',
            'discordapp' => 'Discord',
            'linkedin' => 'LinkedIn',
            'pinterest' => 'Pinterest',
            'semrush' => 'SemrushBot',
            'ahrefs' => 'AhrefsBot',
            'mj12bot' => 'MJ12bot',
            'dotbot' => 'DotBot',
            'petalbot' => 'PetalBot',
            'bingpreview' => 'BingPreview',
            'lighthouse' => 'Lighthouse',
            'pingdom' => 'Pingdom',
            'uptimerobot' => 'UptimeRobot',
            'curl' => 'curl',
            'wget' => 'Wget',
            'python-requests' => 'Python Requests',
            'python-urllib' => 'Python urllib',
            'go-http-client' => 'Go HTTP Client',
            'headless' => 'Headless Browser',
            'monitor' => 'Uptime Monitor',
        ];
        $lower = strtolower($ua);
        foreach ($known as $needle => $name) {
            if (str_contains($lower, $needle)) {
                return $name;
            }
        }

        return 'Bot';
    }

    /**
     * Browser detection. Marker order matters: Edge/Opera/Samsung must be
     * checked before Chrome, Chrome before Safari, because all of them
     * advertise "Safari/" for compatibility reasons.
     *
     * @return array{0: string, 1: string} [name, version]
     */
    private static function detectBrowser(string $ua): array
    {
        $candidates = [
            ['Microsoft Edge', ['Edg/', 'EdgA/', 'Edge/']],
            ['Opera', ['OPR/', 'Opera/']],
            ['Samsung Internet', ['SamsungBrowser/']],
            ['Vivaldi', ['Vivaldi/']],
            ['Firefox', ['Firefox/', 'FxiOS/']],
            ['Chrome', ['CriOS/', 'Chrome/']],
            ['Safari', ['Version/', 'Safari/']],
            ['Internet Explorer', ['MSIE ', 'Trident/']],
            ['Postman', ['PostmanRuntime/']],
        ];
        foreach ($candidates as [$name, $markers]) {
            foreach ($markers as $marker) {
                $version = self::versionAfter($ua, $marker);
                if ($version !== null) {
                    return [$name, $version];
                }
            }
        }

        return ['Unknown', ''];
    }

    /**
     * Extract the version token that directly follows a marker such as
     * "Chrome/". Returns null when the marker is absent.
     */
    private static function versionAfter(string $ua, string $marker): ?string
    {
        $pos = strpos($ua, $marker);
        if ($pos === false) {
            return null;
        }
        $rest = substr($ua, $pos + strlen($marker));
        if (preg_match('/^[0-9][0-9A-Za-z._-]*/', $rest, $m) === 1) {
            return $m[0];
        }

        return '';
    }

    private static function detectOs(string $ua): string
    {
        if (preg_match('/Windows NT ([0-9.]+)/', $ua, $m) === 1) {
            return match ($m[1]) {
                '10.0' => 'Windows 10/11',
                '6.3' => 'Windows 8.1',
                '6.2' => 'Windows 8',
                '6.1' => 'Windows 7',
                default => 'Windows',
            };
        }
        if (str_contains($ua, 'Windows Phone')) {
            return 'Windows Phone';
        }
        if (preg_match('/Android ([0-9.]+)/', $ua, $m) === 1) {
            return 'Android ' . $m[1];
        }
        if (str_contains($ua, 'Android')) {
            return 'Android';
        }
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPod')) {
            return 'iOS';
        }
        if (str_contains($ua, 'iPad')) {
            return 'iPadOS';
        }
        if (str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh')) {
            return 'macOS';
        }
        if (str_contains($ua, 'CrOS')) {
            return 'ChromeOS';
        }
        if (str_contains($ua, 'FreeBSD')) {
            return 'FreeBSD';
        }
        if (str_contains($ua, 'OpenBSD')) {
            return 'OpenBSD';
        }
        if (str_contains($ua, 'Linux')) {
            return 'Linux';
        }

        return 'Unknown';
    }

    private static function detectDevice(string $ua): string
    {
        if (
            str_contains($ua, 'iPad')
            || str_contains($ua, 'Tablet')
            || (str_contains($ua, 'Android') && !str_contains($ua, 'Mobile'))
        ) {
            return 'Tablet';
        }
        if (
            str_contains($ua, 'Mobi')
            || str_contains($ua, 'iPhone')
            || str_contains($ua, 'iPod')
            || str_contains($ua, 'Windows Phone')
        ) {
            return 'Mobile';
        }
        if (str_contains($ua, 'SmartTV') || str_contains($ua, 'AppleTV') || str_contains($ua, 'GoogleTV')) {
            return 'TV';
        }

        return 'Desktop';
    }

    private static function stripWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
