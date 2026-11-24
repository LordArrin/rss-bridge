![RSS-Bridge](static/not_boring_logo_blank.png)

# RSS-Bridge

A modern fork of [RSS-Bridge](https://github.com/RSS-Bridge/rss-bridge) — latest PHP on Alpine, strict types, safe bridge loading, working proxies and hardened networking, with the legacy baggage removed.

[![LICENSE](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.6-777BB4.svg?logo=php)](https://www.php.net/)
[![Docker Image](https://img.shields.io/badge/docker-lordarrin%2Frss--bridge-blue.svg?logo=docker)](https://github.com/LordArrin/rss-bridge/pkgs/container/rss-bridge)
![Build Status](https://github.com/LordArrin/rss-bridge/actions/workflows/build.yml/badge.svg)

**📖 Documentation, configuration and guides: [lordarrin.github.io/rss-bridge](https://lordarrin.github.io/rss-bridge/)**

## Why this fork

- **Modern PHP 8.6** with strict types — upstream still targets min 7.4
- **Native `\Dom\HTMLDocument`** parser instead of the legacy `simple_html_dom`
- **Safe bridge loading** — each bridge runs in an isolated process; a broken one can't crash the app
- **Instant front page** — metadata cache renders regardless of bridge count
- **Working proxies** — FlareSolverr, Telegram (TgWS) and plain HTTP
- **Browser-grade requests** — curl-impersonate with BoringSSL TLS fingerprints
- **Hardened SSRF protection** — DNS pinning via `CURLOPT_RESOLVE` and strict CIDR blocking

## Quick start

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

Open <http://localhost:3000/>. Drop a `*Bridge.php` into `./config/bridges-v2/` and restart to add a custom bridge. See the [documentation](https://lordarrin.github.io/rss-bridge/) for details.

## Third-party components

Bundled in the image (Composer/vendor dependencies are not shipped):

| Component | License |
|-----------|---------|
| [PHP](https://www.php.net/) | PHP License 3.01 |
| [OpenSSL](https://openssl-library.org/) | Apache-2.0 |
| [curl-impersonate](https://github.com/lexiforest/curl-impersonate) | MIT |
| [BoringSSL](https://github.com/google/boringssl) | Apache-2.0 |
| [Freenginx](https://freenginx.org/) | BSD-2-Clause |
| [PCRE2](https://www.pcre.org/) | BSD-3-Clause |
| [zlib-ng](https://github.com/zlib-ng/zlib-ng) | Zlib |
| [Mimalloc](https://github.com/microsoft/mimalloc) | MIT |
| [Memcached](https://memcached.org/) | BSD-3-Clause |
| [libevent](https://libevent.org/) | BSD-3-Clause |
| [Supervisor](http://supervisord.org/) | BSD-style |
| [APCu](https://github.com/krakjoe/apcu) | PHP License 3.01 |
| [msgpack](https://github.com/msgpack/msgpack-php) | BSD-3-Clause |
| [zstd](https://github.com/kjdev/php-ext-zstd) / [brotli](https://github.com/kjdev/php-ext-brotli) | MIT |
| [memcached (PHP ext)](https://github.com/php-memcached-dev/php-memcached) | PHP License 3.01 |

Base image: Alpine Linux. Bundled Alpine runtime libraries (ICU, libxml2, SQLite, libsodium, tidy, libmemcached, …) retain their upstream licenses.

## License

[AGPL-3.0-or-later](LICENSE).