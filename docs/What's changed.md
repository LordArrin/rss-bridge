# Writing Bridges for LordArrin's RSS-Bridge Fork

This guide covers writing bridges specifically for this fork. It focuses on modern PHP 8.5, the native `\Dom\HTMLDocument` API, and PSR-4 namespacing — all of which differ significantly from the upstream RSS-Bridge project.

## Key Differences from Upstream

| Feature | Upstream RSS-Bridge | This Fork |
|---|---|---|
| PHP version | 7.4+ | 8.5+ |
| Strict types | Optional | Required (`declare(strict_types=1)`) |
| HTML parser | `simple_html_dom` (embedded) | `\Dom\HTMLDocument` (PHP native, PHP 8.4+) |
| New bridges | `bridges/` (global namespace) | `bridges-v2/` with `RSSBridge\Bridges` namespace |
| HTTP client | Custom, optional curl-impersonate | curl-impersonate baked into Alpine image |
| Markdown | Embedded Parsedown 1.7.4 | `erusev/parsedown` 1.8+ via Composer |
| URL joining | Embedded | `busybee/urljoin` via Composer |

## Directory Structure

- **`bridges/`** — legacy bridges (kept for compatibility, global namespace)
- **`bridges-v2/`** — new bridges (PSR-4, namespaced, strict types)

**Always place new bridges in `bridges-v2/`.** Legacy `bridges/` is kept only to avoid breaking upstream compatibility.

## Minimal Bridge Template

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ExampleBridge extends BridgeAbstract
{
    const NAME = 'Example';
    const URI = 'https://example.com/';
    const DESCRIPTION = 'Returns example content';
    const MAINTAINER = 'yourname';
    const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $html = $this->fetchHtml(self::URI);
        $title = $html->querySelector('h1')?->textContent ?? 'Untitled';

        $this->items[] = [
            'title' => $title,
            'uri' => self::URI,
            'content' => 'Hello, world',
            'timestamp' => time(),
            'uid' => 'example-1',
        ];
    }
}
```

## Required Constants

All bridges must define at least these constants:

```php
const NAME = 'Display Name';           // Human-readable name
const URI = 'https://example.com/';    // Canonical URL
const DESCRIPTION = 'What it does';    // Short description
const MAINTAINER = 'yourname';         // GitHub username or name
const CACHE_TIMEOUT = 3600;            // Cache duration in seconds
```

## Optional: Parameters

Define user-configurable inputs:

```php
const PARAMETERS = [
    '' => [  // Default context
        'username' => [
            'name' => 'Username',
            'type' => 'text',           // text, list, checkbox, number
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
    ],
    // Additional contexts (optional):
    'By tag' => [
        'tag' => [
            'name' => 'Tag name',
            'type' => 'text',
            'required' => true,
        ],
    ],
];
```

Access parameters via `$this->getInput('username')`.

## Optional: Configuration

For secrets and sensitive values (API tokens, cookies), use `CONFIGURATION`:

```php
const CONFIGURATION = [
    'api_token' => ['required' => true],
    'session_cookie' => ['required' => false],
];
```

Access via `$this->getOption('api_token')`. These are defined in `config.ini.php`:

```ini
[ExampleBridge]
api_token = "your-token-here"
```

## HTML Parsing with Native DOM

**Never use `simple_html_dom` functions in new bridges.** Use the native PHP 8.4+ `\Dom\HTMLDocument` API instead.

### Fetching HTML

```php
private function fetchHtml(string $url): \Dom\HTMLDocument
{
    $html = getContents($url);
    if (empty($html)) {
        throwServerException("Failed to fetch {$url}");
    }

    libxml_use_internal_errors(true);
    $dom = \Dom\HTMLDocument::createFromString($html);
    libxml_use_internal_errors(false);

    return $dom;
}
```

`libxml_use_internal_errors` suppresses warnings for malformed HTML, which is extremely common on real-world sites.

### Selectors

```php
// Single element (returns \Dom\Element or null)
$title = $dom->querySelector('h1.title');
$author = $dom->querySelector('article .author a');

// Multiple elements (returns \Dom\NodeList — iterable)
foreach ($dom->querySelectorAll('article.post') as $post) {
    // ...
}

// Convert to array if you need array_* functions
$items = iterator_to_array($dom->querySelectorAll('li'));
```

### Accessing Data

| Property | Type | Example |
|---|---|---|
| `$node->textContent` | `string` | Plain text, strips HTML |
| `$node->innerHTML` | `string` | Inner HTML |
| `$node->outerHTML` | `string` | Element + inner HTML |
| `$node->getAttribute('href')` | `?string` | Attribute value |
| `$node->tagName` | `string` | Tag name (lowercase) |
| `$node->parentElement` | `?\Dom\Element` | Parent element |
| `$node->parentNode` | `?\Dom\Node` | Parent node (can be document) |
| `$node->childNodes` | `\Dom\NodeList` | Children |
| `$dom->saveHTML()` | `string` | Full document HTML |

### Example: Parsing a List

```php
foreach ($dom->querySelectorAll('.post-list .post') as $post) {
    $link = $post->querySelector('a.title');
    if ($link === null) continue;

    $title = trim($link->textContent);
    $uri = $this->absoluteUrl((string)$link->getAttribute('href'));
    $timeNode = $post->querySelector('time');
    $timestamp = $timeNode !== null
        ? strtotime((string)$timeNode->getAttribute('datetime'))
        : null;

    $this->items[] = [
        'title' => $title,
        'uri' => $uri,
        'timestamp' => $timestamp ?: time(),
        'uid' => $uri,
        'content' => $post->querySelector('.summary')?->textContent ?? $title,
    ];
}
```

### Traversing Parents

```php
// Walk up the tree
$current = $node;
while ($current !== null && $current instanceof \Dom\Element) {
    if ($current->tagName === 'article') {
        return $current;
    }
    $current = $current->parentElement;  // use parentElement, not parentNode
}
```

## Working with JSON APIs

For JSON-based sites, skip HTML entirely:

```php
public function collectData(): void
{
    $url = 'https://api.example.com/posts';
    $json = getContents($url, ['Accept: application/json']);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throwServerException('Invalid JSON response');
    }

    foreach ($data['items'] ?? [] as $item) {
        $this->items[] = [
            'title' => $item['title'] ?? 'Untitled',
            'uri' => $item['url'] ?? self::URI,
            'content' => $item['body'] ?? '',
            'timestamp' => isset($item['date']) ? strtotime($item['date']) : time(),
            'uid' => (string)($item['id'] ?? uniqid()),
        ];
    }
}
```

## Error Handling

Use the standard exceptions — never throw raw `\Exception`:

```php
// User made a bad request
throwClientException('Invalid user ID');

// Server-side or upstream issue
throwServerException('Failed to fetch data');

// Rate limit hit
throwRateLimitException();

// HTTP-level error with code
throw new HttpException('Not Found', 404);
```

All exceptions are caught upstream and rendered into the feed output.

## Feed Item Structure

Every item must include these fields:

```php
[
    'title'     => 'Item Title',        // Required
    'uri'       => 'https://...',       // Required
    'content'   => '<p>HTML body</p>',  // Required
    'timestamp' => 1700000000,          // Unix timestamp (int), optional
    'author'    => 'Alice',             // Optional
    'uid'       => 'unique-id',         // Globally unique, optional
    'categories' => ['news', 'tech'],   // Optional
    'enclosures' => ['https://img.jpg'], // Optional media
]
```

## Caching

The bridge cache is available via `$this->cache`:

```php
$cacheKey = 'example_' . md5($url);
$cached = $this->cache->get($cacheKey);

if ($cached !== null) {
    return $cached;
}

// ... fetch data ...

$this->cache->set($cacheKey, $data, 3600);  // TTL in seconds
```

Note that `getContents()` and `getSimpleHTMLDOMCached()` already cache HTTP responses internally — use the bridge cache only for parsed/processed data.

## Proxy / Cloudflare Bypass

For Cloudflare-protected sites, use the proxy system:

```php
$html = getProtectedContents($url, 'flaresolverr', [
    'cookies' => [
        ['name' => 'session', 'value' => 'abc123', 'domain' => 'example.com'],
    ],
    'cache_ttl' => 900,
]);
```

Then parse with `\Dom\HTMLDocument::createFromString($html)`.

The proxy profile is configured in `config.ini.php`:

```ini
[proxy_profile_flaresolverr]
type = "FlareSolverr"
url = "http://localhost:8191"
```

## Relative URLs

Use the bundled `urljoin` for resolving relative URLs:

```php
use function urljoin;

$absolute = urljoin('https://example.com/posts/', '/img/photo.jpg');
// => 'https://example.com/img/photo.jpg'
```

## Markdown in Content

Render Markdown to HTML with Parsedown:

```php
$parsedown = new \Parsedown();
$html = $parsedown->text($markdown);
```

## Running Quality Checks

From the repo root:

```bash
# Lint your bridge
vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php

# Auto-fix style issues
vendor/bin/phpcbf --standard=phpcs.xml bridges-v2/YourBridge.php

# Static analysis
vendor/bin/phpstan analyse bridges-v2/YourBridge.php --level=5

# Run tests
vendor/bin/phpunit
```

From inside the Docker container:

```bash
docker exec rss-bridge vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php
```

## Testing Your Bridge

1. Restart the container after adding a new bridge:
   ```bash
   docker restart rss-bridge
   ```
2. Open `http://localhost:3000/` in a browser.
3. Find your bridge in the list, fill in parameters, click "Show result".
4. Inspect the resulting feed for correctness.

## Checklist Before Committing

- [ ] File is in `bridges-v2/` (not `bridges/`)
- [ ] `declare(strict_types=1)` at top
- [ ] `namespace RSSBridge\Bridges;` declared
- [ ] Class is `final` and extends `BridgeAbstract`
- [ ] All 5 required constants defined (NAME, URI, DESCRIPTION, MAINTAINER, CACHE_TIMEOUT)
- [ ] No `simple_html_dom` functions (`find()`, `plaintext`, `innertext`, etc.)
- [ ] Uses `\Dom\HTMLDocument` with `querySelector` / `querySelectorAll`
- [ ] `libxml_use_internal_errors(true)` wraps HTML parsing
- [ ] Null-safe operator `?->` used for optional elements
- [ ] Absolute URLs constructed with `urljoin()`
- [ ] `phpcs` passes with no warnings
- [ ] Bridge tested with real data in Docker

## Full Reference Example

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class BlogBridge extends BridgeAbstract
{
    const NAME = 'Example Blog';
    const URI = 'https://blog.example.com/';
    const DESCRIPTION = 'Latest posts from Example Blog';
    const MAINTAINER = 'yourname';
    const CACHE_TIMEOUT = 1800;

    const PARAMETERS = [
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
        $dom = $this->fetchHtml($url);

        $posts = $dom->querySelectorAll('article.post');
        $count = 0;

        foreach ($posts as $post) {
            if ($count >= $limit) break;

            $link = $post->querySelector('a.post-title');
            if ($link === null) continue;

            $uri = urljoin(self::URI, (string)$link->getAttribute('href'));
            $title = trim($link->textContent);

            $excerpt = $post->querySelector('.excerpt')?->textContent ?? $title;
            $dateStr = $post->querySelector('time')?->getAttribute('datetime');
            $timestamp = $dateStr ? strtotime($dateStr) : null;
            $author = $post->querySelector('.author-name')?->textContent;

            $this->items[] = [
                'title'     => $title,
                'uri'       => $uri,
                'content'   => '<p>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>',
                'timestamp' => $timestamp ?: time(),
                'author'    => $author !== null ? trim($author) : null,
                'uid'       => $uri,
            ];

            $count++;
        }

        if ($this->items === []) {
            throwServerException('No posts found. The site may have changed its layout.');
        }
    }

    private function fetchHtml(string $url): \Dom\HTMLDocument
    {
        $html = getContents($url);
        if (empty($html)) {
            throwServerException("Empty response from {$url}");
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }
}
```

## Questions?

Open an issue on [github.com/LordArrin/rss-bridge](https://github.com/LordArrin/rss-bridge) — the community is happy to help new bridge authors.