![RSS-Bridge](static/not_boring_logo_blank.png)

RSS-Bridge is a PHP web application. Works with PHP 8.5.

[![LICENSE](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Docker Image](https://img.shields.io/badge/docker-lordarrin%2Frss--bridge-blue.svg?logo=docker)](https://hub.docker.com/r/lordarrin/rss-bridge)

## A subset of bridges (15/381)

* `Nginx`: Returns Nginx releases with changelogs and other news
* `Pawchive`: Returns posts from Pawchive
* `MSISupport`: Returns BIOS, drivers, manuals, and utilities updates for MSI products
* `GigabyteSupport`: Returns BIOS and drivers updates for Gigabyte products
* `AlpineReleases`: Alpine Linux releases with branch info
* `Rule34`: Returns images from rule34.xxx search
* `RuStore`: Returns application updates with its changelog
* `Fimfiction`: Returns chapter updates for stories on Fimfiction
* `AuthorToday`: Returns updates for stories by chapter
* `SearchFloor`: Returns updates to all books by an author or a single book by ID
* `Vk2`: Returns posts from the public feed
* `Telegram2`: Returns the recent publications from a public Telegram channel. Supports embedded media content, hides ads and unsupported content
* `IA Panorama`: News feed of the Russian satirical information agency "Panorama"
* `GitHubRelease`: Returns releases for a GitHub repository (excludes tag-only entries)
* `FirefoxReleaseNotes`: Returns recent Firefox releases with changelogs for each version
* `CodebergReleases`: Returns releases for a Codeberg repository with changelogs and downloads

## Installation

This fork of RSS-Bridge is distributed as a Docker image based on Alpine Linux: `lordarrin/rss-bridge`.

### Quick start

```bash
docker run -d \
  --name rss-bridge \
  -p 3000:80 \
  -v $(pwd)/config:/config \
  lordarrin/rss-bridge
```

Browse http://localhost:3000/

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
    restart: unless-stopped
```

```bash
docker-compose up -d
```

### Custom configuration

You can place a custom `config.ini.php` and additional bridge files into the `./config` directory.

**You must restart the container for custom changes to take effect.**

```bash
docker restart rss-bridge
```

## Configuration

### Password-protect the instance (token)

In `config/config.ini.php`:

```ini
[authentication]
token = "hunter2"
```

### Password-protect the instance (HTTP Basic Auth)

```ini
[authentication]
enable = true
username = "alice"
password = "cat"
```

### Enable all bridges

```ini
enabled_bridges[] = *
```

### Enable specific bridges

```ini
enabled_bridges[] = TwitchBridge
enabled_bridges[] = GettrBridge
```

### Cache backend

Default: `file`. Alternatives: `sqlite`, `memcached`, `null`.

```ini
[cache]
type = "sqlite"
```

### Error reporting

```ini
[error]
; "feed" = as part of the feed (default)
; "http" = as HTTP error message
; "none" = no errors are reported
output = "feed"

; How often an error must occur before it is reported
report_limit = 3
```

## Troubleshooting

### Clear all cache

```bash
docker exec rss-bridge bin/cache-clear
```

### Prune expired cache

```bash
docker exec rss-bridge bin/cache-prune
```

### Fix "The FileCache path is not writable"

Ensure the `/config` volume has write permissions for the container user.

### Fix "attempt to write a readonly database"

Ensure the SQLite files inside `/config/cache/` are writable.

## Creating a new bridge

New bridge files MUST have `declare(strict_types=1);` at the top:

```php
<?php

declare(strict_types=1);

class BearBlogBridge extends BridgeAbstract
{
    const NAME = 'BearBlog (bearblog.dev)';

    public function collectData()
    {
        $dom = getSimpleHTMLDOM('https://herman.bearblog.dev/blog/');
        foreach ($dom->find('.blog-posts li') as $li) {
            $a = $li->find('a', 0);
            $this->items[] = [
                'title' => $a->plaintext,
                'uri' => 'https://herman.bearblog.dev' . $a->href,
            ];
        }
    }
}
```

Place the file in `bridges/` inside your `config` volume and restart the container.

## Feed item structure

```php
$item = [
    'uri' => 'https://example.com/blog/hello',
    'title' => 'Hello world',
    'timestamp' => 1668706254,
    'author' => 'Alice',
    'content' => 'Here be item content',
    'enclosures' => [
        'https://example.com/foo.png',
        'https://example.com/bar.png'
    ],
    'categories' => ['news', 'tech'],
    'uid' => 'e7147580c8747aad',
];
```

## Output formats

* `Atom` - Atom feed
* `Html` - Simple HTML page
* `Json` - JSON
* `Mrss` - MRSS feed
* `Plaintext` - Raw text
* `Sfeed` - TAB-separated text

## Cache backends

* `File`
* `SQLite`
* `Memcached`
* `Array`
* `Null`

## License

This fork is licensed under the [GNU Affero General Public License v3.0](LICENSE).

RSS-Bridge uses third-party libraries with their own licenses:

* [`Parsedown`](https://github.com/erusev/parsedown) - MIT
* [`PHP Simple HTML DOM Parser`](https://simplehtmldom.sourceforge.io/docs/1.9/index.html) - MIT
* [`php-urljoin`](https://github.com/fluffy-critter/php-urljoin) - MIT
