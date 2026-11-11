![RSS-Bridge](static/not_boring_logo_blank.png)

A fork of [RSS-Bridge](https://github.com/RSS-Bridge/rss-bridge), rewritten for latest PHP on Alpine Linux. Less legacy code, proper type coverage, safe bridge loading, and working proxy support.

[![LICENSE](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.5+-777BB4.svg?logo=php)](https://www.php.net/)
[![Docker Image](https://img.shields.io/badge/docker-lordarrin%2Frss--bridge-blue.svg?logo=docker)](https://github.com/LordArrin/rss-bridge/pkgs/container/rss-bridge)
![Build Status](https://github.com/LordArrin/rss-bridge/actions/workflows/build.yml/badge.svg)

## What's Inside

- **PHP 8.5** with strict types wherever it made sense
- **Alpine-based image** - noticeably smaller than the Debian variant
- **SafeBridgeLoader** - bridges are loaded in an isolated process, so a broken bridge cannot crash the entire application
- **Metadata cache** - the main page renders instantly, regardless of the bridge count
- **Native PHP DOM parser** (`\Dom\HTMLDocument`) replacing the legacy `simple_html_dom`
- **Proxy profiles** - FlareSolverr, Telegram via TgWS, and plain HTTP proxies
- **PSR-4 for bridges** - a single `RSSBridge\Bridges` namespace with proper autoloading
- **Advanced SSRF protection** - process-wide DNS caching, IP pinning via `CURLOPT_RESOLVE`, and strict CIDR blocking to prevent DNS-rebinding and internal network access
- Removed `DetectAction`, `FindfeedAction`, the donation UI, and other leftovers from the original project

## Installation

### Docker Compose

```yaml
services:
  rss-bridge:
    image: lordarrin/rss-bridge:latest
    container_name: rss-bridge
    ports:
      - "3000:80"
    volumes:
      - ./config:/config
    restart: unless-stopped
```

```bash
docker compose up -d
```

Open `http://localhost:3000/` to see the main page with all available bridges.

## Docker Images

Multi-architecture Docker images are built weekly and published to GitHub Container Registry. Each build includes the latest security patches from Alpine, latest OpenSSL, hardened Freenginx, curl-impersonate (with BoringSSL for TLS fingerprint impersonation), and Mimalloc memory allocator.

### Available Tags

| Tag | Architecture | Optimization Level | Use Case |
|-----|--------------|-------------------|----------|
| `latest` | amd64 + arm64 | Baseline (`x86-64` / `armv8-a`) | Maximum compatibility - runs on any x86_64 CPU (2003+) or ARMv8 device |
| `latest-modern` | amd64 + arm64 | High (`x86-64-v3` / `armv8.2-a` with crypto extensions) | Modern servers - Intel Haswell+ / AMD Zen+, ARM Cortex-A55+ and newer |

### Date-Tagged Images

Every build is also tagged with its build date for reproducibility (e.g., `2026-09-07`, `2026-09-07-modern`). Use these when you need to pin to a specific build.

### Security

- Images are rebuilt **every Sunday at 03:00 UTC** to include the latest security patches
- All binaries are compiled with full hardening: RELRO, PIE, stack protection, CFI, FORTIFY_SOURCE=3
- Latest OpenSSL is used system-wide (PHP, Nginx, curl)

### Hardware Compatibility Guide

**Use `latest` if:**
- You run on older hardware or VPS with unknown CPU model
- You need guaranteed compatibility with any x86_64 or ARMv8 device
- You deploy in heterogeneous environments

**Use `latest-modern` if:**
- Your server has a modern CPU (Intel Haswell or newer, AMD Zen or newer, ARM Cortex-A55 or newer)
- You want ~15-30% performance improvement through AVX2, AES-NI, and ARM crypto extensions
- You run on cloud providers with modern instances (AWS Graviton3+, Azure, GCP)

### Building from source

```bash
git clone https://github.com/LordArrin/rss-bridge.git
cd rss-bridge
docker build -t yourname/rss-bridge .
```
## Custom Bridges

Place a `.php` file in `/config/bridges-v2/` and restart the container. That's all.

```text
./config/
├── .env
├── .bridges
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

The legacy `/config/bridges/` directory is no longer supported; the container will emit a warning in the logs on startup. Migrate any such bridges to `bridges-v2/` with a proper namespace.

For a detailed guide on bridge development, see the separate `BRIDGES.md` document.

## Project Structure

```text
rss-bridge/
├── actions/              # Request handlers: FrontpageAction, DisplayAction
├── bridges-v2/           # Bridges (PSR-4, RSSBridge\Bridges namespace)
├── caches/               # Cache backends: File, SQLite, Memcached, ...
├── config/               # Config of internal services - nginx, PHP ...
├── formats/              # Output formats: Atom, Mrss, Json, Html, Plaintext, Sfeed
├── lib/                  # Core: BridgeAbstract, BridgeFactory, SafeBridgeLoader, IpResolver, ...
├── docs/                 # Additional documentation
├── middlewares/          # HTTP middleware stack
├── proxies/              # Proxy profile implementations
├── templates/            # HTML templates
├── quirks/               # Rendering helpers: template, html, seo, media_embed, ...
├── bin/                  # CLI utilities: cache-clear, cache-prune
├── static/               # CSS, JS, images
├── supervisor/           # Supervisor. Launches all internal services - Memcached, Nginx, PHP.
├── docker-entrypoint.sh  # Container entrypoint
└── Dockerfile
```

## Migration from Original RSS-Bridge

### Breaking Changes

- The minimum PHP version has been raised from 7.4 to 8.5, with strict types enforced
- Bridges now live in `bridges-v2/` under the `RSSBridge\Bridges` namespace
- `DetectAction` and `FindfeedAction` have been removed - automatic feed detection by URL is no longer available
- The donation UI and `getDonationURI()` have been removed
- `simple_html_dom` has been replaced with the built-in `\Dom\HTMLDocument`
- All broken/abandoned bridges have been removed or rewritten.

## License

This fork is distributed under the [GNU Affero General Public License v3.0](LICENSE).

Third-party libraries used:

| Library | License |
|---------|---------|
| [Parsedown](https://github.com/erusev/parsedown) | MIT |
| [php-urljoin](https://github.com/fluffy-critter/php-urljoin) | MIT |
| [PHPUnit](https://phpunit.de/) | BSD-3-Clause |
| [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) | BSD-3-Clause |
| [PHPStan](https://phpstan.org/) | MIT |
| [memcached](https://github.com/memcached/memcached) | Combined - BSD-3-Clause and others |
| [freenginx](https://freenginx.org/LICENSE) | Combined - BSD-3-Clause and others |
