# Shortlink

**A self-hosted URL shortener with click analytics, a REST API and a JavaScript-free HTML dashboard — in a single copyable folder.**

![Language](https://img.shields.io/badge/language-PHP%208.1%2B-777bb3)
![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)
![Database](https://img.shields.io/badge/database-file%20(JSON%20%2B%20flock)-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Tests](https://img.shields.io/badge/tests-plain%20assert%20suite-orange)

---

## Overview

Shortlink is a complete URL shortening service you can drop onto any host that
runs PHP 8.1+. It shortens long URLs, tracks every click with
privacy-preserving analytics (salted IP hashes, hostname-only referrers,
parsed user agents), and exposes everything through a small REST API plus a
server-rendered dashboard.

It deliberately avoids Composer, databases, JavaScript build steps and
frameworks. Persistence is a set of flock-protected JSON documents with
atomic rename-based writes, which makes backup and deployment as simple as
copying a directory. If you ever outgrow it, the data is plain JSON — easy to
migrate anywhere.

## Features

- **Short links with optional controls** — custom aliases, expiry timestamps,
  per-link click limits and soft enable/disable toggles.
- **Click analytics per link** — total clicks, unique visitors (by salted IP
  hash), bot traffic, daily buckets, top referrers, browser/OS/device
  breakdowns and a recent-clicks feed.
- **REST API** — create, list, inspect, toggle and delete links; consistent
  JSON error envelopes with machine readable codes.
- **HTML dashboard** — KPI cards, a pure-CSS bar chart and data tables,
  rendered entirely on the server (zero JavaScript, strict CSP).
- **Bot detection** — crawlers, previewers and scripted clients (curl, wget,
  python-requests, Googlebot, …) are flagged and reported separately.
- **Rate limiting** — fixed-window counters per client IP, shared across
  workers because they live in storage, not in process memory.
- **Admin token** — mutating admin endpoints require an `X-Api-Token`
  compared with `hash_equals()`.
- **Safe persistence** — every write is `lock → read → modify → tmpfile →
  rename → unlock`, so concurrent FPM workers and CLI commands never corrupt
  a document.
- **Maintenance CLI** — list, stats, create, delete, prune, export (CSV/JSON),
  info and health commands with meaningful exit codes.
- **Privacy first** — raw IP addresses are never written to disk; only a
  16-character truncated SHA-256 hash keyed with a per-installation salt.

## Requirements

| Requirement | Minimum                              | Notes                                       |
|-------------|--------------------------------------|---------------------------------------------|
| PHP         | 8.1                                  | `json`, `pcre`, `spl`, `hash` (all bundled) |
| Web server  | PHP built-in server, Apache, or nginx| `mod_rewrite` for Apache                    |
| Disk        | a writable directory                 | defaults to `./data`                        |
| Composer    | not needed                           | PSR-4 autoloading is built in               |
| Database    | none                                 | JSON documents under the storage path       |

## Installation

### 1. Get the code

```bash
git clone https://github.com/buifkhanh57-hub/shortlink.git
cd shortlink
```

### 2. Development: PHP built-in server

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

`public/index.php` acts as the router script; requests to real files under
`public/` (e.g. `/assets/style.css`) return `false` so the server streams
them directly.

### 3. Production: nginx

```nginx
server {
    listen 80;
    server_name short.example.com;
    root /var/www/shortlink/public;
    index index.php;

    # Existing files (CSS assets) are served directly.
    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    # Never expose internals even if the docroot is misconfigured.
    location ~ ^/(src|bin|config|tests|data)/ { deny all; }
}
```

### 4. Production: Apache

Point the vhost `DocumentRoot` at `public/`; the bundled `.htaccess` routes
everything except real files to `index.php`. Ensure `AllowOverride All`.

### 5. Configure

Either edit `config/config.php` or export environment variables:

```bash
export SHORTLINK_BASE_URL="https://short.example.com"
export SHORTLINK_API_TOKEN="$(openssl rand -hex 24)"
export SHORTLINK_IP_SALT="$(openssl rand -hex 16)"
```

## Quick Start

Start the server, then create your first link:

```bash
curl -s -X POST http://127.0.0.1:8080/api/links \
     -H 'Content-Type: application/json' \
     -d '{"url": "https://example.com/very/long/article?page=2"}'
```

```json
{
  "link": {
    "code": "kQ3f9Zx",
    "short_url": "http://127.0.0.1:8080/kQ3f9Zx",
    "target_url": "https://example.com/very/long/article?page=2",
    "title": "example.com",
    "custom": false,
    "created_at": "2026-02-10T09:15:04+00:00",
    "updated_at": "2026-02-10T09:15:04+00:00",
    "expires_at": null,
    "max_clicks": null,
    "clicks": 0,
    "is_active": true,
    "status": "active"
  },
  "rate_limit": { "limit": 10, "remaining": 9, "reset_at": 1770714960 }
}
```

Open it, then read the stats:

```bash
curl -s http://127.0.0.1:8080/api/links/kQ3f9Zx/stats | head -40
```

```json
{
  "link": { "code": "kQ3f9Zx", "...": "..." },
  "totals": {
    "clicks": 1,
    "unique_visitors": 1,
    "bot_clicks": 0,
    "clicks_today": 1,
    "window_days": 30,
    "clicks_in_window": 1
  },
  "daily": [
    { "date": "2026-02-10", "clicks": 1 }
  ],
  "referrers": [ { "referrer": "direct", "clicks": 1 } ],
  "browsers":  [ { "browser": "Chrome", "clicks": 1 } ],
  "os":        [ { "os": "Windows 10/11", "clicks": 1 } ]
}
```

## Usage

### Endpoints

| Method | Path                        | Auth        | Description                                  |
|--------|-----------------------------|-------------|----------------------------------------------|
| POST   | `/api/links`                | rate-limited| Create a link (JSON or form body)            |
| GET    | `/api/links`                | admin token | Paginated list with filters and sorting      |
| GET    | `/api/links/{code}`         | public      | Fetch a single link                          |
| GET    | `/api/links/{code}/stats`   | public      | Full click analytics (`?days=1..365`)        |
| PATCH  | `/api/links/{code}`         | admin token | Toggle availability `{"is_active": bool}`    |
| DELETE | `/api/links/{code}`         | admin token | Delete link + click history (204)            |
| GET    | `/{code}`                   | rate-limited| 302 redirect + click tracking                |
| GET    | `/dashboard`                | public      | HTML dashboard                               |
| GET    | `/health`                   | public      | JSON health probe (200/503)                  |

Admin endpoints require `X-Api-Token: <token>` matching
`security.api_token` in the configuration.

### Creating links — request fields

| Field        | Type   | Required | Constraints                                        |
|--------------|--------|----------|----------------------------------------------------|
| `url`        | string | yes      | absolute `http(s)` URL, max 2048 chars             |
| `alias`      | string | no       | 3–32 chars, `[A-Za-z0-9_-]`, not a reserved word   |
| `expires_at` | string | no       | ISO-8601 in the future, ≤ 3650 days ahead          |
| `max_clicks` | int    | no       | 1 – 100 000 000                                    |
| `title`      | string | no       | free text, defaults to the target hostname         |

Example with every option:

```bash
curl -s -X POST http://127.0.0.1:8080/api/links \
     -H 'Content-Type: application/json' \
     -d '{
       "url": "https://example.com/campaign",
       "alias": "spring-2026",
       "expires_at": "2026-06-01T00:00:00+00:00",
       "max_clicks": 5000,
       "title": "Spring campaign"
     }'
```

### Error format

Every API error uses the same envelope:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The submitted URL is not valid.",
    "details": { "url": "URL scheme \"ftp:\" is not allowed (only http and https)." }
  }
}
```

| Status | Code                | Cause                                    |
|--------|---------------------|------------------------------------------|
| 401    | `internal_error`    | missing/invalid admin token (401 status) |
| 404    | `not_found`         | unknown code or route                    |
| 405    | `method_not_allowed`| wrong verb, `Allow` header lists options |
| 409    | `conflict`          | custom alias already in use              |
| 410    | (HTML page)         | expired / disabled / exhausted link      |
| 422    | `validation_failed` | invalid url/alias/expiry/max_clicks      |
| 429    | `rate_limited`      | rate limit hit, `Retry-After` header set |

### Admin list query parameters

| Parameter  | Default  | Values                                              |
|------------|----------|-----------------------------------------------------|
| `page`     | 1        | ≥ 1                                                 |
| `per_page` | 20       | 1 – 100                                             |
| `status`   | all      | `all`, `active`, `expired`, `exhausted`, `disabled` |
| `search`   | –        | substring match on code, URL and title              |
| `sort`     | newest   | `newest`, `oldest`, `most_clicks`, `code`           |

```bash
curl -s -H 'X-Api-Token: YOURTOKEN' \
     'http://127.0.0.1:8080/api/links?status=active&sort=most_clicks&per_page=5'
```

### Console

```bash
php bin/console list --status=active --sort=most_clicks
php bin/console stats kQ3f9Zx --days=14
php bin/console create https://example.com --alias=docs --max-clicks=100
php bin/console delete docs            # asks for confirmation
php bin/console prune --days=90        # expired links + old click data
php bin/console export --what=clicks --format=csv --out=clicks.csv
php bin/console info
php bin/console health
```

Exit codes: `0` success, `1` runtime error, `2` usage error.

## Configuration

All values live in `config/config.php` and can be overridden via environment
variables.

| Key                        | Default                  | Meaning                                            |
|----------------------------|--------------------------|----------------------------------------------------|
| `base_url`                 | `http://127.0.0.1:8080`  | public origin used in `short_url` values           |
| `storage.path`             | `./data`                 | directory for the JSON documents                   |
| `storage.max_click_log_entries` | 20000               | raw click rows kept (aggregates unaffected)        |
| `storage.pretty_json`      | `true`                   | pretty-print documents on disk                     |
| `links.code_length`        | 7                        | random base62 code length (≈ 3.5 trillion codes)   |
| `links.url_max_length`     | 2048                     | maximum accepted target URL length                 |
| `links.max_expiry_days`    | 3650                     | upper bound for `expires_at`                       |
| `rate_limit.create_max`    | 10 per 60 s              | POST `/api/links` per client IP                    |
| `rate_limit.redirect_max`  | 120 per 60 s             | redirects per client IP                            |
| `security.api_token`       | `change-me-local-token`  | shared secret for admin endpoints                  |
| `security.ip_hash_salt`    | dev salt                 | salt for visitor IP hashes — set your own!         |
| `security.trusted_proxies` | loopback                 | peers allowed to set `X-Forwarded-For`             |

## Click analytics explained

Each redirect appends one click event and updates per-day aggregates under a
single lock:

- **Clicks** — every redirect counts, bots included but flagged separately.
- **Unique visitors** — the client IP is hashed with `sha256(ip | salt)` and
  truncated to 16 hex chars. Per-day hash sets are unioned, so the metric is
  "unique visitor-days" — the same person on two days counts twice. Raw IPs
  are never stored; rotating the salt invalidates the history.
- **Referrers** — reduced to the hostname (`google.com`), with the special
  values `direct` (no header) and `internal` (your own domain).
- **Browsers / OS / device** — parsed from the User-Agent with an explicit
  marker table (Edge → Opera → Samsung → Firefox → Chrome → Safari → IE, so
  compatibility tokens never mislead the classifier).
- **Bots** — crawler/preview/script patterns (Googlebot, curl, wget,
  python-requests, …) are detected, named and excluded from visitor counts.
- **Daily buckets** — pre-aggregated per day and code, so the stats endpoint
  never scans the raw log. `?days=N` (1–365) controls the chart window.

## Project Structure

```
shortlink/
├── bin/
│   └── console                 # maintenance CLI (list/stats/create/delete/prune/export/info/health)
├── config/
│   └── config.php              # all settings, env-var overridable
├── public/                     # web root
│   ├── index.php               # front controller: autoloader, container, routes, error mapping
│   ├── .htaccess               # Apache rewrites + security headers
│   └── assets/
│       └── style.css           # dashboard theme (dark, framework-free)
├── src/
│   ├── ClickTracker.php        # click recording, UA parser, IP hashing, referrer classes
│   ├── CodeGenerator.php       # base62 encode/decode, random codes, alias rules, reserved list
│   ├── RateLimiter.php         # fixed-window counters in storage, injectable clock
│   ├── Router.php              # static + pattern routes, constraints, 404/405, named routes
│   ├── Validator.php           # URL/alias/expiry/pagination/filter validation
│   ├── Controller/
│   │   ├── ShortenController.php    # POST /api/links
│   │   ├── RedirectController.php   # GET /{code} + click tracking + 410 pages
│   │   ├── StatsController.php      # GET /api/links/{code}/stats
│   │   ├── AdminController.php      # list/show/toggle/delete + token auth
│   │   └── DashboardController.php  # GET /dashboard, /health, /
│   ├── Exception/
│   │   ├── ShortlinkException.php   # base: status code + error code payload
│   │   ├── ValidationException.php  # 422 with per-field details
│   │   ├── NotFoundException.php    # 404
│   │   ├── ConflictException.php    # 409
│   │   ├── RateLimitException.php   # 429 + Retry-After
│   │   └── StorageException.php     # 500, persistence failures
│   ├── Http/
│   │   ├── Request.php         # request value object (superglobals or manual)
│   │   └── Response.php        # response value object (json/html/redirect factories)
│   ├── Storage/
│   │   ├── JsonStore.php       # flock + atomic rename JSON documents
│   │   ├── LinkRepository.php  # link records, pagination, lifecycle status
│   │   └── ClickRepository.php # append-only log + daily aggregate buckets
│   └── View/
│       └── DashboardHtml.php   # server-side HTML renderer (escaped everywhere)
├── tests/
│   └── run_tests.php           # plain-assert suite, no PHPUnit/web server needed
└── data/                       # created at runtime: links.json, clicks.json, rate_limit.json
```

## Architecture

Four cleanly separated layers:

1. **HTTP layer** (`Http/`, `Router`) — immutable Request/Response value
   objects and a pattern router with static + dynamic routes, method
   negotiation (HEAD→GET), 404/405 handling and named routes. Handlers are
   plain callables, so any controller method works and every part is
   unit-testable without a web server.
2. **Controllers** (`Controller/`) — thin adapters that read the request,
   enforce rate limits/tokens, call repositories and return Response
   objects. Errors are raised as typed exceptions, never as ad-hoc arrays.
3. **Domain services** (`CodeGenerator`, `Validator`, `RateLimiter`,
   `ClickTracker`) — pure, framework-free logic: base62 codes, input
   validation, fixed-window counting and user-agent classification.
4. **Storage** (`Storage/`) — a tiny document store where every mutation is
   serialized through an flock-protected lock file and published with an
   atomic `rename(2)`. LinkRepository keeps one document of records;
   ClickRepository pairs a bounded raw log with pre-aggregated daily buckets
   so stats stay O(days), not O(clicks).

Design decisions worth knowing:

- **No Composer** — a ten-line `spl_autoload_register` closure maps the
  `Shortlink\` namespace to `src/`, following PSR-4.
- **Exceptions carry HTTP semantics** — status code + machine error code live
  on the exception; the front controller maps them once, in order.
- **Denormalized click counter** — `links[code].clicks` is maintained on
  redirect for O(1) status checks (exhausted links answer 410 instantly).
- **Bots are tracked but never counted as visitors** — explicit product
  decision, keeps unique-visitor graphs human.
- **Everything is escapable-output** — the dashboard renders through one
  `htmlspecialchars()` helper and ships a strict CSP with no inline styles
  (chart heights are CSS level classes).

## Testing

```bash
php tests/run_tests.php
```

The suite runs 8 sections — base62 round-trips and alias rules, URL/option
validation, routing (including constraints, 405 and HEAD), HTTP value
objects, storage (including corrupted-document handling), the rate limiter
with an injected fake clock, the user-agent parser against 10 real-world UA
strings, and a full integration flow that creates a link through the router,
redirects it, reads stats, toggles and deletes it. It exits `0` on success
and `1` listing every failure. No PHPUnit, no network, no web server.

## FAQ

**Why file storage instead of SQLite?**
Portability and zero setup: the `data/` directory is the whole database —
`cp -r` is a backup, `rsync` is a migration. The write path (flock + tmpfile
+ rename) is safe for the traffic profile of a self-hosted shortener. If you
outgrow it, the JSON documents map 1:1 to SQL rows.

**Is the click log a privacy problem?**
No raw IP is ever persisted — only a truncated salted hash. Referrer paths
and query strings are dropped, and UA strings are parsed into categories
before storage. Rotate `ip_hash_salt` to invalidate all history.

**What happens when a link expires or hits its click limit?**
The redirect answers 410 with an explanatory page; the link and its stats
remain until deleted or pruned. `prune --days=N` removes links expired more
than N days ago plus click data older than N days.

**Can two workers corrupt a document?**
No. Every mutation takes an exclusive `flock` on a sidecar lock file, and
readers only ever see complete documents because new content is published
via atomic rename.

**How do I change the look of the dashboard?**
Edit `public/assets/style.css`; the HTML structure is produced by
`src/View/DashboardHtml.php` with stable class names and no inline styles.

**Does it work behind Cloudflare / a reverse proxy?**
Add the proxy IP to `security.trusted_proxies`; `X-Forwarded-For` /
`X-Real-IP` are then honored for rate limiting and IP hashing — and only
then, so direct clients cannot spoof their address.

## Roadmap

- [ ] SQLite storage adapter behind the same repository interfaces
- [ ] QR-code endpoint (`GET /api/links/{code}/qr`)
- [ ] Per-link API tokens instead of one shared admin secret
- [ ] UTM parameter builder in the dashboard
- [ ] Bulk import/export of link mappings
- [ ] Optional click payload archiving to NDJSON files
- [ ] A/B target rotation for campaign links

## License

MIT License — see below.

Copyright (c) 2026 Bui Bao Khanh

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

---
**by Bui Bao Khanh**
