```markdown
# Writing Bridges for LordArrin's RSS-Bridge Fork

This guide covers writing bridges specifically for this fork. It focuses on modern PHP 8.5, the native `\Dom\HTMLDocument` API, PSR-4 namespacing, and the `quirks/` utility library — all of which differ significantly from the upstream RSS-Bridge project.

## Key Differences from Upstream

| Feature | Upstream RSS-Bridge | This Fork |
|---|---|---|
| PHP version | 7.4+ | 8.5+ |
| Strict types | Optional | Required (`declare(strict_types=1)`) |
| HTML parser | `simple_html_dom` (embedded) | `\Dom\HTMLDocument` (PHP native, PHP 8.4+) |
| New bridges | `bridges/` (global namespace) | `bridges-v2/` with `RSSBridge\Bridges` namespace |
| HTML utilities | Inline in each bridge | Centralized in `quirks/` directory |
| HTTP client | Custom, optional curl-impersonate | curl-impersonate baked into Alpine image |
| Markdown | Embedded Parsedown 1.7.4 | `erusev/parsedown` 1.8+ via Composer |
| URL joining | Embedded | `busybee/urljoin` via Composer |

## Directory Structure

- **`bridges/`** — legacy bridges (kept for compatibility, global namespace)
- **`bridges-v2/`** — new bridges (PSR-4, namespaced, strict types)
- **`quirks/`** — utility functions for bridges (HTML helpers, DOM manipulation, media processing)
- **`lib/`** — core framework classes (partially migrated to `RSSBridge` namespace)

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

## Quirks Utilities

The `quirks/` directory contains battle-tested utility functions that handle common bridge tasks. **Use these instead of writing your own implementations.**

All quirks functions are globally available (loaded via Composer `files` autoload) and have `declare(strict_types=1)` enabled.

### HTML Generation Helpers (`quirks/html.php`)

Safely generate HTML fragments for feed item content:

```php
// Escape user input (prevents XSS)
$safe = e($userInput);  // htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE

// Mark trusted HTML as safe (semantic no-op)
$trusted = raw($preRenderedHtml);

// Truncate long strings
$short = truncate($longText, 200, '...');

// Generate HTML tags with validation
$input = html_input(['type' => 'text', 'name' => 'q', 'value' => 'search']);
$option = html_option('United States', 'us', true);
$div = html_tag('div', 'Hello', ['class' => 'greeting', 'id' => 'hello']);
```

`html_tag()` validates attribute names against a whitelist, preventing accidental injection of event handlers like `onclick`.

### DOM Manipulation (`quirks/dom.php`)

Process HTML content for feed items:

```php
// Sanitize HTML: remove scripts, iframes, keep only safe attributes
$clean = sanitize($html);
$clean = sanitize($html, ['script', 'iframe'], ['href', 'src'], ['p', 'strong']);

// Convert relative URLs to absolute (processes img, a, script, link, video, audio, iframe)
$absolute = defaultLinkTo($html, 'https://example.com/');

// Convert lazy-loaded images to static (data-src, data-srcset, data-lazy-src)
$static = convertLazyLoading($html);

// Replace CSS background-image with <img> tags
$withImgs = backgroundToImg($html);

// Break dangerous tags (script, iframe, link) while keeping them visible
$broken = break_annoying_html_tags($html);
```

**Recommended pattern** for processing article HTML:

```php
$html = getContents($articleUrl);
$html = defaultLinkTo($html, $articleUrl);  // Fix relative URLs
$html = convertLazyLoading($html);          // Handle lazy-loaded images
$html = sanitize($html);                    // Remove scripts/iframes
$item['content'] = $html;
```

### String Extraction (`quirks/extract.php`)

Extract data from HTML strings or inline JavaScript:

```php
// Extract text between delimiters
$data = extractFromDelimiters($html, 'window.data = ', ';');

// Remove HTML sections
$clean = stripWithDelimiters($html, '<script>', '</script>');
$clean = stripRecursiveHTMLSection($html, 'div', '<div class="ads">');
```

### Srcset Parsing (`quirks/srcset.php`)

Handle responsive images:

```php
// Parse srcset attribute
$entries = parseSrcset('image-320w.jpg 320w, image-1024w.jpg 1024w');
// => ['320w' => 'image-320w.jpg', '1024w' => 'image-1024w.jpg']

// Get largest image URL
$largest = parseSrcsetLargestImageUrl($srcset);
```

### Media Processing (`quirks/media.php`)

```php
// Convert Markdown to HTML
$html = markdownToHtml($markdown, ['breaksEnabled' => true]);

// Generate YouTube embed or thumbnail
$embed = handleYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
// Returns <iframe> or <picture> with WebP/JPEG srcset based on config
```

### SEO Metadata Extraction (`quirks/seo.php`)

Extract Open Graph, Twitter Cards, and JSON-LD metadata automatically:

```php
$metadata = html_find_seo_metadata($html);
// Returns array with keys: uri, title, content, timestamp, enclosures, author

// Use in bridge:
$item = [
    'title' => $metadata['title'] ?? $fallbackTitle,
    'uri' => $metadata['uri'] ?? $articleUrl,
    'content' => $articleContent,
    'timestamp' => $metadata['timestamp'] ?? time(),
    'author' => $metadata['author'] ?? null,
    'enclosures' => $metadata['enclosures'] ?? [],
];
```

### Template Rendering (`quirks/template.php`)

Used internally by RSS-Bridge actions to render HTML pages. Rarely needed in bridges, but available if you need to generate complex HTML:

```php
$html = render('my-template.html.php', ['items' => $items]);
```

## HTML Parsing with Native DOM

**Never use `simple_html_dom` functions directly in new bridges** (`str_get_html()`, `->find()`, `->plaintext`, `->innertext`). Instead, use the native PHP 8.4+ `\Dom\HTMLDocument` API for new parsing logic.

**Exception:** The functions in `quirks/dom.php` (like `sanitize()`, `defaultLinkTo()`) use `simple_html_dom` internally — that's fine. Treat them as black-box utilities.

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

**Preferred: Use `defaultLinkTo()` from quirks** to process all relative URLs in an HTML fragment at once:

```php
$html = getContents($articleUrl);
$html = defaultLinkTo($html, $articleUrl);  // Fixes img src, a href, video src, etc.
```

**Manual approach:** Use `urljoin()` for individual URLs:

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

Or use the quirks wrapper with options:

```php
$html = markdownToHtml($markdown, [
    'breaksEnabled' => true,
    'markupEscaped' => true,
]);
```

## Namespaced Core Classes

Several core classes have been migrated to the `RSSBridge` namespace. When you need them in your bridge, import them explicitly:

```php
use RSSBridge\Configuration;
use RSSBridge\FeedItem;
use RSSBridge\FeedParser;

// Access configuration
$apiKey = Configuration::getConfig('MyBridge', 'api_key');

// Parse an existing RSS/Atom feed
$parser = new FeedParser();
$feed = $parser->parseFeed($xmlString);
```

`BridgeAbstract` is in the `RSSBridge` namespace, so all bridges must import it with `use RSSBridge\BridgeAbstract;`.

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
- [ ] No direct `simple_html_dom` calls (`str_get_html()`, `->find()`, `->plaintext`)
- [ ] Uses `\Dom\HTMLDocument` with `querySelector` / `querySelectorAll` for new parsing
- [ ] Uses quirks utilities (`defaultLinkTo()`, `convertLazyLoading()`, `sanitize()`) for HTML processing
- [ ] `libxml_use_internal_errors(true)` wraps HTML parsing
- [ ] Null-safe operator `?->` used for optional elements
- [ ] Relative URLs handled via `defaultLinkTo()` or `urljoin()`
- [ ] `phpcs` passes with no warnings
- [ ] Bridge tested with real data in Docker

## Full Reference Example

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

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
            if ($count >= $limit) {
                break;
            }

            $link = $post->querySelector('a.post-title');
            if ($link === null) {
                continue;
            }

            $uri = $link->getAttribute('href');
            $title = trim($link->textContent);

            // Fetch full article
            $articleHtml = getContents($uri);
            
            // Process article HTML with quirks utilities
            $articleHtml = defaultLinkTo($articleHtml, $uri);
            $articleHtml = convertLazyLoading($articleHtml);
            $articleHtml = sanitize($articleHtml);

            // Extract metadata for fallback
            $metadata = html_find_seo_metadata($articleHtml);

            $dateStr = $post->querySelector('time')?->getAttribute('datetime');
            $timestamp = $dateStr !== null ? strtotime($dateStr) : ($metadata['timestamp'] ?? time());
            $author = $post->querySelector('.author-name')?->textContent ?? ($metadata['author'] ?? null);

            $this->items[] = [
                'title' => $title,
                'uri' => $uri,
                'content' => $articleHtml,
                'timestamp' => $timestamp !== false ? $timestamp : time(),
                'author' => $author !== null ? trim($author) : null,
                'uid' => $uri,
                'enclosures' => $metadata['enclosures'] ?? [],
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
```
