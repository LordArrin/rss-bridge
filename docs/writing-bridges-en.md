```markdown
# Writing Bridges for LordArrin's RSS-Bridge Fork

This guide covers bridge development for this fork. It assumes familiarity with the upstream [RSS-Bridge](https://github.com/RSS-Bridge/rss-bridge) project and focuses on what is different here: PHP 8.5 with strict types, the native `\Dom\HTMLDocument` API, PSR-4 namespacing, and the `quirks/` utility library.

## Key Differences from Upstream

| Feature | Upstream RSS-Bridge | This Fork |
|---|---|---|
| PHP version | 7.4+ | 8.5+ |
| Strict types | Optional | Required (`declare(strict_types=1)`) |
| HTML parser | `simple_html_dom` (embedded) | `\Dom\HTMLDocument` (PHP 8.4+) |
| Bridge location | `bridges/` (global namespace) | `bridges-v2/` with `RSSBridge\Bridges` namespace |
| HTML utilities | Inline in each bridge | Centralized in `quirks/` directory |
| Markdown | Embedded Parsedown | `erusev/parsedown` via Composer |
| URL joining | Embedded | `busybee/urljoin` via Composer |
| Bridge loading | Direct `include` | Sandboxed subprocess via `SafeBridgeLoader` |

Legacy `bridges/` directory is not supported. All bridges live in `bridges-v2/`.

## Minimal Bridge

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use function RSSBridge\Exceptions\throwServerException;

final class ExampleBridge extends BridgeAbstract
{
    public const NAME = 'Example';
    public const URI = 'https://example.com/';
    public const DESCRIPTION = 'Returns example content';
    public const MAINTAINER = 'yourname';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $dom = getSimpleHTMLDOM(self::URI);
        $title = $dom->querySelector('h1')?->textContent ?? 'Untitled';

        $this->items[] = [
            'title' => $title,
            'uri' => self::URI,
            'content' => '<p>Hello, world</p>',
            'timestamp' => time(),
            'uid' => 'example-1',
        ];
    }
}
```

Save this as `bridges-v2/ExampleBridge.php` and restart the container. The bridge will appear on the main page.

## Required Constants

Every bridge must define these five constants. All must have explicit `public` visibility:

```php
public const NAME = 'Display Name';
public const URI = 'https://example.com/';
public const DESCRIPTION = 'What this bridge does';
public const MAINTAINER = 'yourname';
public const CACHE_TIMEOUT = 3600;
```

- `NAME` — human-readable name shown on the main page
- `URI` — canonical URL of the source site
- `DESCRIPTION` — one-line description shown on the main page
- `MAINTAINER` — GitHub username or name, used in error reports
- `CACHE_TIMEOUT` — default cache TTL in seconds

## Parameters

Define user-configurable inputs via the `PARAMETERS` constant. The first key is the context name (use `''` for a single default context):

```php
public const PARAMETERS = [
    '' => [
        'username' => [
            'name' => 'Username',
            'type' => 'text',
            'required' => true,
            'exampleValue' => 'alice',
            'title' => 'Enter the username',
        ],
        'limit' => [
            'name' => 'Item limit',
            'type' => 'number',
            'defaultValue' => 10,
        ],
        'include_replies' => [
            'name' => 'Include replies',
            'type' => 'checkbox',
            'defaultValue' => false,
        ],
        'category' => [
            'name' => 'Category',
            'type' => 'list',
            'values' => [
                'All' => '',
                'Tech' => 'tech',
                'Design' => 'design',
            ],
            'defaultValue' => '',
        ],
    ],
];
```

Supported parameter types: `text`, `number`, `checkbox`, `list`.

Access parameter values inside `collectData()`:

```php
$username = (string)$this->getInput('username');
$limit = (int)($this->getInput('limit') ?: 10);
$includeReplies = (bool)$this->getInput('include_replies');
$category = (string)$this->getInput('category');
```

### Multiple Contexts

A bridge may define several contexts, each with its own parameter set. The user picks one context on the main page:

```php
public const PARAMETERS = [
    'By user' => [
        'username' => [
            'name' => 'Username',
            'required' => true,
        ],
    ],
    'By tag' => [
        'tag' => [
            'name' => 'Tag name',
            'required' => true,
        ],
    ],
    'global' => [
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'defaultValue' => 20,
        ],
    ],
];
```

Parameters under the `'global'` context are appended to every other context. Use `$this->queriedContext` to detect which context the user selected.

## Configuration (Secrets)

For API tokens, cookies, and other sensitive values, define them in `CONFIGURATION` and set the actual values in `config.ini.php`:

```php
public const CONFIGURATION = [
    'api_token' => ['required' => true],
    'session_cookie' => ['required' => false],
];
```

In `config/config.ini.php`:

```ini
[ExampleBridge]
api_token = "your-token-here"
session_cookie = "abc123"
```

Access values in the bridge:

```php
$token = $this->getOption('api_token');
$cookie = $this->getOption('session_cookie');
```

If a required option is missing, `loadConfiguration()` throws an exception before `collectData()` runs.

## Fetching and Parsing HTML

Use `getSimpleHTMLDOM()` for one-off HTML fetching. It handles caching internally and returns a native `\Dom\HTMLDocument`:

```php
$dom = getSimpleHTMLDOM('https://example.com/posts');
$dom = getSimpleHTMLDOM($url, $headers, $curlOptions);
```

For cached repeated fetches (e.g. images fetched for each item), use `getSimpleHTMLDOMCached()`:

```php
$dom = getSimpleHTMLDOMCached($articleUrl, 86400);
```

When you need the raw HTTP response (for JSON APIs, binary data, or custom parsing), use `getContents()`:

```php
$html = getContents($url);
$json = getContents($url, ['Accept: application/json']);
$response = getContents($url, [], [], true);  // returns full Response object
```

### Selectors

`\Dom\HTMLDocument` uses standard CSS selectors:

```php
// Single element (returns \Dom\Element or null)
$title = $dom->querySelector('h1.title');
$author = $dom->querySelector('article .author a');

// Multiple elements (returns iterable \Dom\NodeList)
foreach ($dom->querySelectorAll('article.post') as $post) {
    // ...
}
```

### Accessing Data

| Property / Method | Type | Description |
|---|---|---|
| `$node->textContent` | `string` | Plain text, HTML stripped |
| `$node->innerHTML` | `string` | Inner HTML |
| `$node->outerHTML` | `string` | Element with inner HTML |
| `$node->getAttribute('href')` | `?string` | Attribute value |
| `$node->tagName` | `string` | Tag name (lowercase) |
| `$node->parentElement` | `?\Dom\Element` | Parent element |
| `$node->childNodes` | `\Dom\NodeList` | Children |

Use the null-safe operator `?->` for optional elements:

```php
$author = $post->querySelector('.author-name')?->textContent;
$date = $post->querySelector('time')?->getAttribute('datetime');
```

### Traversing the Tree

```php
$current = $node;
while ($current !== null && $current instanceof \Dom\Element) {
    if ($current->tagName === 'article') {
        break;
    }
    $current = $current->parentElement;
}
```

## Utility Functions (`quirks/`)

The `quirks/` directory contains battle-tested helpers. All are loaded globally via Composer `files` autoload — no import needed.

### HTML Generation (`quirks/html.php`)

```php
// Escape user input (prevents XSS)
$safe = e($userInput);

// Truncate long strings
$short = truncate($longText, 200, '...');

// Generate HTML tags with attribute validation
$div = html_tag('div', 'Hello', ['class' => 'greeting']);
$input = html_input(['type' => 'text', 'name' => 'q', 'value' => 'search']);
$option = html_option('United States', 'us', true);

// Neutralize dangerous tags (script, iframe, link) while keeping visible text
$clean = break_annoying_html_tags($html);
```

`html_tag()` validates attribute names against a whitelist, preventing injection of event handlers like `onclick`.

### String Extraction (`quirks/extract.php`)

Extract data from HTML or inline JavaScript:

```php
// Extract text between delimiters
$data = extractFromDelimiters($html, 'window.data = ', ';');

// Remove sections between delimiters
$clean = stripWithDelimiters($html, '<script>', '</script>');

// Remove recursive HTML sections
$clean = stripRecursiveHTMLSection($html, 'div', '<div class="ads">');
```

### Srcset Parsing (`quirks/srcset.php`)

```php
// Parse srcset attribute
$entries = parseSrcset('image-320w.jpg 320w, image-1024w.jpg 1024w');
// => ['320w' => 'image-320w.jpg', '1024w' => 'image-1024w.jpg']

// Get the largest image URL
$largest = parseSrcsetLargestImageUrl($srcset);
```

### SEO Metadata (`quirks/seo.php`)

Extract Open Graph, Twitter Cards, and JSON-LD metadata:

```php
$metadata = html_find_seo_metadata($html);
// Returns array with keys: uri, title, content, timestamp, enclosures, author

$item = [
    'title' => $metadata['title'] ?? $fallbackTitle,
    'uri' => $metadata['uri'] ?? $articleUrl,
    'content' => $articleContent,
    'timestamp' => $metadata['timestamp'] ?? time(),
    'author' => $metadata['author'] ?? null,
    'enclosures' => $metadata['enclosures'] ?? [],
];
```

### Media Processing (`quirks/media.php`)

```php
// Convert Markdown to HTML
$html = markdownToHtml($markdown, ['breaksEnabled' => true]);

// Generate YouTube embed or thumbnail
$embed = handleYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
```

### Binary Embedding (`quirks/media_embed.php`)

Embed remote images and media as data URIs:

```php
$dataUri = media_embed_url_to_data_uri($imageUrl, $options);
$binary = media_embed_fetch_binary($url, $options);
$mime = media_embed_mime_from_url($url);
$mime = media_embed_mime_from_extension('png');
$bytes = media_embed_parse_size('1.5MB');
```

## Working with JSON APIs

For JSON-based sites, skip HTML entirely:

```php
public function collectData(): void
{
    $url = 'https://api.example.com/posts';
    $json = getContents($url, ['Accept: application/json']);
    $data = Json::decode($json);

    if (!is_array($data)) {
        throwServerException('Invalid JSON response');
    }

    foreach ($data['items'] ?? [] as $entry) {
        $this->items[] = [
            'title' => $entry['title'] ?? 'Untitled',
            'uri' => $entry['url'] ?? self::URI,
            'content' => $entry['body'] ?? '',
            'timestamp' => isset($entry['date']) ? strtotime($entry['date']) : time(),
            'uid' => (string)($entry['id'] ?? uniqid()),
        ];
    }
}
```

`Json::decode()` and `Json::encode()` are provided by the `RSSBridge` namespace (loaded via Composer classmap). They throw `\JsonException` on invalid input.

## Error Handling

Exception helper functions live in the `RSSBridge\Exceptions` namespace. Import them at the top of your bridge:

```php
use function RSSBridge\Exceptions\throwClientException;
use function RSSBridge\Exceptions\throwServerException;
use function RSSBridge\Exceptions\throwRateLimitException;
```

Then use them inside `collectData()`:

```php
// User provided bad input
throwClientException('Invalid user ID');

// Server-side or upstream issue
throwServerException('Failed to fetch data from API');

// Rate limit hit upstream
throwRateLimitException('API rate limit exceeded');

// HTTP-level error with a specific code
throw new \RSSBridge\Http\HttpException('Not Found', 404);
```

All exceptions are caught by the framework and rendered according to the `error.output` setting in `config.ini.php`.

## Feed Item Structure

Every item must include at least `title`, `uri`, and `content`:

```php
$this->items[] = [
    'title' => 'Item Title',
    'uri' => 'https://example.com/post/123',
    'content' => '<p>HTML body</p>',
    'timestamp' => 1700000000,              // Unix timestamp, optional
    'author' => 'Alice',                    // Optional
    'uid' => 'unique-id',                   // Globally unique, optional
    'categories' => ['news', 'tech'],       // Optional
    'enclosures' => ['https://example.com/image.jpg'], // Optional media
];
```

The `uid` field is used by feed readers to detect duplicates. If omitted, the framework generates one from the item content.

## Caching

HTTP responses are cached automatically by `getContents()` for up to 10 days, respecting `Cache-Control` headers. For caching parsed or processed data, use the bridge-level cache helpers:

```php
$cacheKey = 'example_' . md5($url);
$cached = $this->loadCacheValue($cacheKey);

if ($cached !== null) {
    return $cached;
}

// ... fetch and process data ...

$this->saveCacheValue($cacheKey, $processedData, 3600);
```

Alternatively, access the cache backend directly:

```php
$this->cache->get($cacheKey);
$this->cache->set($cacheKey, $data, $ttl);
```

## Proxy Profiles

For Cloudflare-protected sites or sites requiring specific IPs, use the proxy system:

```php
// Fetch raw HTML via a named proxy profile
$html = getProtectedContents($url, 'flaresolverr');

// Fetch and parse into \Dom\HTMLDocument
$dom = getProtectedSimpleHTMLDOM($url, 'tgws');

// Fetch binary content (images, videos)
$binary = getProtectedBinary($url, 'direct');
```

Proxy profiles are defined in `config/config.ini.php`:

```ini
[proxy_profile_flaresolverr]
type = "FlareSolverr"
url = "http://flaresolverr:8191"
timeout = 60000

[proxy_profile_tgws]
type = "TgWS"
socks_url = "socks5h://user:pass@192.168.1.1:14444"

[proxy_profile_direct]
type = "Direct"
```

If a profile is referenced but not defined, `getProtectedContents()` throws a `ClientException` with a hint about the missing configuration.

## Relative URLs

For individual URLs, use `urljoin()` from the `busybee/urljoin` package:

```php
$absolute = urljoin('https://example.com/posts/', '/img/photo.jpg');
// => 'https://example.com/img/photo.jpg'
```

For processing many URLs inside an HTML fragment, combine `\Dom\HTMLDocument` selectors with `urljoin()` in a loop:

```php
$dom = getSimpleHTMLDOM($articleUrl);
foreach ($dom->querySelectorAll('img[src]') as $img) {
    $src = $img->getAttribute('src');
    if ($src !== null && !str_starts_with($src, 'http')) {
        $img->setAttribute('src', urljoin($articleUrl, $src));
    }
}
$html = $dom->saveHTML();
```

## Namespaced Core Classes

Several core classes live in the `RSSBridge` namespace. Import them explicitly when needed:

```php
use RSSBridge\BridgeAbstract;
use RSSBridge\Configuration;
use RSSBridge\FeedItem;
use RSSBridge\FeedParser;
use RSSBridge\Http\HttpException;
use RSSBridge\Http\Response;
```

`BridgeAbstract` is always required — every bridge extends it.

## Running Quality Checks

From the repository root:

```bash
# Lint your bridge
vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php

# Auto-fix style issues
vendor/bin/phpcbf --standard=phpcs.xml bridges-v2/YourBridge.php

# Static analysis
vendor/bin/phpstan analyse bridges-v2/YourBridge.php --level=5
```

From inside the Docker container:

```bash
docker exec rss-bridge php /app/vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php
```

## Testing Your Bridge

1. Place the bridge file in `config/bridges-v2/` (for custom bridges) or `bridges-v2/` (for built-in ones).
2. Restart the container: `docker restart rss-bridge`.
3. Open `http://localhost:3000/` in a browser.
4. Find your bridge, fill in parameters, and click **Generate feed**.
5. Inspect the resulting feed for correctness.

If the bridge fails to load, check the container logs:

```bash
docker logs rss-bridge | grep -i "YourBridge"
```

The `SafeBridgeLoader` reports compile errors (e.g. signature mismatches) with the exact file and line.

## Checklist Before Committing

- [ ] File is in `bridges-v2/`
- [ ] `declare(strict_types=1)` at the top
- [ ] `namespace RSSBridge\Bridges;` declared
- [ ] Class is `final` and extends `BridgeAbstract`
- [ ] All five required constants defined with `public const`
- [ ] No direct `simple_html_dom` calls (`str_get_html()`, `->find()`, `->plaintext`)
- [ ] Uses `getSimpleHTMLDOM()` or `\Dom\HTMLDocument` for parsing
- [ ] Null-safe operator `?->` used for optional elements
- [ ] Exception helpers imported via `use function RSSBridge\Exceptions\...`
- [ ] Relative URLs handled via `urljoin()` or manual DOM processing
- [ ] `phpcs` passes with no errors
- [ ] Bridge tested with real data in Docker

## Full Reference Example

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use function RSSBridge\Exceptions\throwServerException;

final class BlogBridge extends BridgeAbstract
{
    public const NAME = 'Example Blog';
    public const URI = 'https://blog.example.com/';
    public const DESCRIPTION = 'Latest posts from Example Blog';
    public const MAINTAINER = 'yourname';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        '' => [
            'category' => [
                'name' => 'Category',
                'type' => 'list',
                'values' => [
                    'All' => '',
                    'Tech' => 'tech',
                    'Design' => 'design',
                ],
                'defaultValue' => '',
            ],
            'limit' => [
                'name' => 'Posts per feed',
                'type' => 'number',
                'defaultValue' => 20,
            ],
        ],
    ];

    public function collectData(): void
    {
        $category = (string)$this->getInput('category');
        $limit = (int)($this->getInput('limit') ?: 20);

        $url = $category !== '' ? self::URI . "category/{$category}/" : self::URI;
        $dom = getSimpleHTMLDOM($url);

        $count = 0;
        foreach ($dom->querySelectorAll('article.post') as $post) {
            if ($count >= $limit) {
                break;
            }

            $link = $post->querySelector('a.post-title');
            if ($link === null) {
                continue;
            }

            $uri = $link->getAttribute('href');
            $title = trim($link->textContent);
            if ($uri === null || $uri === '') {
                continue;
            }

            // Fetch full article
            $articleDom = getSimpleHTMLDOMCached($uri, 86400);
            $articleHtml = $articleDom->querySelector('.entry-content')?->innerHTML ?? '';

            // Neutralize dangerous tags
            $articleHtml = break_annoying_html_tags($articleHtml);

            // Extract metadata for fallback values
            $metadata = html_find_seo_metadata($articleHtml);

            $dateStr = $post->querySelector('time')?->getAttribute('datetime');
            $timestamp = $dateStr !== null ? strtotime($dateStr) : null;
            $author = $post->querySelector('.author-name')?->textContent ?? null;

            $this->items[] = [
                'title' => $title,
                'uri' => urljoin(self::URI, $uri),
                'content' => $articleHtml,
                'timestamp' => $timestamp !== null && $timestamp !== false
                    ? $timestamp
                    : ($metadata['timestamp'] ?? time()),
                'author' => $author !== null ? trim($author) : ($metadata['author'] ?? null),
                'uid' => $uri,
                'enclosures' => $metadata['enclosures'] ?? [],
            ];

            $count++;
        }

        if ($this->items === []) {
            throwServerException('No posts found. The site may have changed its layout.');
        }
    }
}
```

## Questions?

Open an issue on [github.com/LordArrin/rss-bridge](https://github.com/LordArrin/rss-bridge).
```
