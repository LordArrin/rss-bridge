![RSS-Bridge](static/not_boring_logo_blank.png)

A fork of [RSS-Bridge](https://github.com/RSS-Bridge/rss-bridge), rewritten for PHP 8.5 on Alpine Linux. Less legacy code, proper type coverage, safe bridge loading, and working proxy support.

[![LICENSE](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.5+-777BB4.svg?logo=php)](https://www.php.net/)
[![Docker Image](https://img.shields.io/badge/docker-lordarrin%2Frss--bridge-blue.svg?logo=docker)](https://hub.docker.com/r/lordarrin/rss-bridge)

## What's Inside

- **PHP 8.5** with strict types wherever it made sense
- **Alpine-based image** — noticeably smaller than the Debian variant
- **SafeBridgeLoader** — bridges are loaded in an isolated process, so a broken bridge cannot crash the entire application
- **Metadata cache** — the main page renders instantly, regardless of the bridge count
- **Native PHP DOM parser** (`\Dom\HTMLDocument`) replacing the legacy `simple_html_dom`
- **Proxy profiles** — FlareSolverr, SOCKS5 via TgWS, and plain HTTP proxies
- **PSR-4 for bridges** — a single `RSSBridge\Bridges` namespace with proper autoloading
- Removed `DetectAction`, `FindfeedAction`, the donation UI, and other leftovers from the original project

## Installation

### Docker Compose

```yaml
services:
  rss-bridge:
    image: lordarrin/rss-bridge
    container_name: rss-bridge
    ports:
      - "3000:80"
    volumes:
      - ./config:/config
    environment:
      - HTTP_PORT=80
      - TZ=UTC
    restart: unless-stopped
```

```bash
docker compose up -d
```

Open `http://localhost:3000/` to see the main page with all available bridges.

### Plain docker run

```bash
docker run -d \
  --name rss-bridge \
  -p 3000:80 \
  -v ./config:/config \
  --restart unless-stopped \
  lordarrin/rss-bridge
```

### Building from source

```bash
git clone https://github.com/LordArrin/rss-bridge.git
cd rss-bridge
docker build -t lordarrin/rss-bridge .
```

## Configuration

Everything is configured through `/config/config.ini.php`. The container copies this file on startup, so changes require a container restart.

### Authentication

Token-based (passed via the `token` URL parameter):

```ini
[authentication]
token = "hunter2"
```

Or HTTP Basic Authentication:

```ini
[authentication]
enable = true
username = "alice"
password = "cat"
```

### Enabling Bridges

Enable all discovered bridges:

```ini
[system]
enabled_bridges[] = *
```

Or pick specific ones:

```ini
[system]
enabled_bridges[] = Telegram2Bridge
enabled_bridges[] = BoostyBridge
enabled_bridges[] = RuStoreBridge
```

### Cache

The default backend is `file`. Alternatives include `sqlite`, `memcached`, `null`, and `array`.

```ini
[cache]
type = "sqlite"
custom_timeout = true
```

When `custom_timeout = true`, the bridge form gains a `_cache_timeout` field, letting users set a custom TTL per feed.

### Error Reporting

```ini
[error]
; "feed" — errors appear inside the feed (default)
; "http" — an HTML error page is returned
; "none" — errors are silently logged
output = "feed"

; Number of occurrences before an error is reported
report_limit = 3
```

### Global Proxy

```ini
[proxy]
url = "http://proxy.example.com:8080"
name = "Corporate Proxy"
by_bridge = true
```

With `by_bridge = true`, each bridge form shows a "Disable proxy" checkbox, allowing users to bypass the proxy on a per-request basis.

## Proxy Profiles

For more complex scenarios — bypassing Cloudflare, using residential IPs, and similar — use named proxy profiles. Bridges call them via `getProtectedContents()`, `getProtectedSimpleHTMLDOM()`, or `getProtectedBinary()`.

### Direct

A plain direct request with no proxy. Useful when you have a global proxy configured but need a clean IP for a specific site.

```ini
[proxy_profile_direct]
type = "Direct"
```

### FlareSolverr

For sites behind Cloudflare. Requires a running [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr) instance.

```ini
[proxy_profile_flaresolverr]
type = "FlareSolverr"
url = "http://flaresolverr:8191"
timeout = 60000
```

### TgWS (SOCKS5)

A SOCKS5 proxy via [TgWS](https://github.com/LordArrin/tg-ws-proxy-docker) — useful for bypassing censorship.

```ini
[proxy_profile_tgws]
type = "TgWS"
socks_url = "socks5h://user:pass@192.168.1.1:14444"
connect_timeout = 10
request_timeout = 30
retries = 3
```

### Using Profiles in Bridges

```php
$html = getProtectedContents('https://example.com', 'flaresolverr');
$dom = getProtectedSimpleHTMLDOM('https://example.com', 'tgws');
$binary = getProtectedBinary('https://example.com/image.jpg', 'direct');
```

If a profile is referenced but not defined in `config.ini.php`, `DirectProxy` throws a `ClientException` with a hint about which configuration is missing.

## Custom Bridges

Place a `.php` file in `/config/bridges-v2/` and restart the container. That's all.

```bash
./config/
├── config.ini.php
└── bridges-v2/
    └── MyCustomBridge.php
```

Each bridge must live under the `RSSBridge\Bridges` namespace, extend `BridgeAbstract` or another basic bridge,
start with `declare(strict_types=1);`, and have a filename ending with `Bridge.php`.

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class MyCustomBridge extends BridgeAbstract
{
    public const NAME = 'My Custom Bridge';
    public const URI = 'https://example.com';
    public const DESCRIPTION = 'What this bridge does';
    public const MAINTAINER = 'your-name';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'username' => [
                'name' => 'Username',
                'required' => true,
                'exampleValue' => 'alice',
            ],
        ],
    ];

    public function collectData(): void
    {
        $username = $this->getInput('username');
        $dom = getSimpleHTMLDOM('https://example.com/' . $username);
        // ... populate $this->items[]
    }
}
```

The legacy `/config/bridges/` directory is no longer supported — the container will emit a warning in the logs on startup. Migrate any such bridges to `bridges-v2/` with a proper namespace.

For a detailed guide on bridge development, see the separate `BRIDGES.md` document.

## Project Structure

```
rss-bridge/
├── actions/              # Request handlers: FrontpageAction, DisplayAction
├── bridges-v2/           # Bridges (PSR-4, RSSBridge\Bridges namespace)
├── caches/               # Cache backends: File, SQLite, Memcached, ...
├── formats/              # Output formats: Atom, Mrss, Json, Html, Plaintext, Sfeed
├── lib/                  # Core: BridgeAbstract, BridgeFactory, SafeBridgeLoader, ...
├── middlewares/          # HTTP middleware stack
├── proxies/              # Proxy profile implementations
├── templates/            # HTML templates
├── quirks/               # Rendering helpers: template, html, seo, media_embed, ...
├── bin/                  # CLI utilities: cache-clear, cache-prune
├── config/               # Default configuration
├── static/               # CSS, JS, images
├── docker-entrypoint.sh  # Container entrypoint
└── Dockerfile
```

## Migration from Original RSS-Bridge

### Breaking Changes

- The minimum PHP version has been raised from 7.4 to 8.5, with strict types enforced
- Bridges now live in `bridges-v2/` under the `RSSBridge\Bridges` namespace
- `DetectAction` and `FindfeedAction` have been removed — automatic feed detection by URL is no longer available
- The donation UI and `getDonationURI()` have been removed
- `simple_html_dom` has been replaced with the built-in `\Dom\HTMLDocument`
- All broken/abandoned bridges have been removed or rewritten.

### Migration Steps

```bash
# 1. Back up the current configuration and legacy bridges
cp config/config.ini.php config/config.ini.php.bak
cp -r config/bridges config/bridges.bak

# 2. Pull the new image
docker compose pull

# 3. Move custom bridges to bridges-v2/ and add the namespace
#    (see the "Custom Bridges" section above)

# 4. Restart
docker compose up -d

# 5. Inspect the logs
docker compose logs -f
```

## Troubleshooting

### Logs

```bash
docker logs rss-bridge
docker logs -f rss-bridge   # follow mode
```

### Clearing the Cache

```bash
docker exec rss-bridge php /app/bin/cache-clear      # clear everything
docker exec rss-bridge php /app/bin/cache-prune      # remove only expired entries
```

Note that bridge metadata is rebuilt automatically on every container startup, so there is no need to do it manually.

### "FileCache path is not writable" / "attempt to write a readonly database"

Make sure the `./config` directory is writable by the container user:

```bash
chmod -R 755 ./config
```

### Bridges marked as "Broken"

This typically indicates an incompatible method signature in one of the bridges. Open the logs and search for `Bridge compatibility error` — the output will point to the offending bridge and the exact compile error.

## License

This fork is distributed under the [GNU Affero General Public License v3.0](LICENSE).

Third-party libraries used:

| Library | License |
|---------|---------|
| [Parsedown](https://github.com/erusev/parsedown) | MIT |
| [php-urljoin](https://github.com/fluffy-critter/php-urljoin) | MIT |
| [PHPUnit](https://phpunit.de/) | BSD-3-Clause (dev) |
| [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) | BSD-3-Clause (dev) |
| [PHPStan](https://phpstan.org/) | MIT (dev) |
