![RSS-Bridge](static/not_boring_logo_blank.png)

RSS-Bridge is a PHP web application.

It generates web feeds for websites that don't have one.

Requires PHP 8.5.

[![LICENSE](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Docker Image](https://img.shields.io/badge/docker-lordarrin%2Frss--bridge-blue.svg?logo=docker)](https://hub.docker.com/r/lordarrin/rss-bridge)

## A subset of bridges (15/447)

* `CssSelectorBridge`: [Scrape out a feed using CSS selectors](https://rss-bridge.org/bridge01/#bridge-CssSelectorBridge)
* `FeedMergeBridge`: [Combine multiple feeds into one](https://rss-bridge.org/bridge01/#bridge-FeedMergeBridge)
* `FeedReducerBridge`: [Reduce a noisy feed by some percentage](https://rss-bridge.org/bridge01/#bridge-FeedReducerBridge)
* `FilterBridge`: [Filter a feed by excluding/including items by keyword](https://rss-bridge.org/bridge01/#bridge-FilterBridge)
* `GettrBridge`: [Fetches the latest posts from a GETTR user](https://rss-bridge.org/bridge01/#bridge-GettrBridge)
* `MastodonBridge`: [Fetches statuses from a Mastodon (ActivityPub) instance](https://rss-bridge.org/bridge01/#bridge-MastodonBridge)
* `RedditBridge`: [Fetches posts from a user/subreddit (with filtering options)](https://rss-bridge.org/bridge01/#bridge-RedditBridge)
* `RumbleBridge`: [Fetches channel/user videos](https://rss-bridge.org/bridge01/#bridge-RumbleBridge)
* `SoundcloudBridge`: [Fetches music by username](https://rss-bridge.org/bridge01/#bridge-SoundcloudBridge)
* `TelegramBridge`: [Fetches posts from a public channel](https://rss-bridge.org/bridge01/#bridge-TelegramBridge)
* `ThePirateBayBridge`: [Fetches torrents by search/user/category](https://rss-bridge.org/bridge01/#bridge-ThePirateBayBridge)
* `TikTokBridge`: [Fetches posts by username](https://rss-bridge.org/bridge01/#bridge-TikTokBridge)
* `TwitchBridge`: [Fetches videos from channel](https://rss-bridge.org/bridge01/#bridge-TwitchBridge)
* `XPathBridge`: [Scrape out a feed using XPath expressions](https://rss-bridge.org/bridge01/#bridge-XPathBridge)
* `YoutubeBridge`: [Fetches videos by username/channel/playlist/search](https://rss-bridge.org/bridge01/#bridge-YoutubeBridge)
* `YouTubeCommunityTabBridge`: [Fetches posts from a channel's Posts tab](https://rss-bridge.org/bridge01/#bridge-YouTubeCommunityTabBridge)

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
version: "3.8"

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
```
