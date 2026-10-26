# Writing Bridges for LordArrin's RSS-Bridge Fork

This guide covers bridge development for this fork. It assumes familiarity with the upstream [RSS-Bridge](https://github.com/RSS-Bridge/rss-bridge) project and focuses on what is different here: PHP 8.5 with strict types, the native `\Dom\HTMLDocument` API, PSR-4 namespacing, and the `quirks/` utility library.

## Key Differences from Upstream

| Feature | Upstream RSS-Bridge | This Fork |
|---|---|---|
| PHP version | 7.4+ | 8.5+ |
| Strict types | Optional | Required (`declare(strict_types=1)`) |
| HTML parser | `simple_html_dom` (embedded) | `\Dom\HTMLDocument` (PHP 8.4+) |
| HTML cleanup | Manual | `tidy` extension for malformed HTML |
| Bridge location | `bridges/` (global namespace) | `bridges-v2/` with `RSSBridge\Bridges` namespace |
| HTML utilities | Inline in each bridge | Centralized in `quirks/` directory |
| Markdown | Embedded Parsedown | `erusev/parsedown` via Composer |
| URL joining | Embedded | `busybee/urljoin` via Composer |
| Bridge loading | Direct `include` | Sandboxed subprocess via `SafeBridgeLoader` |

Legacy `bridges/` directory is not supported. All bridges live in `bridges-v2/`.

## Code Quality and Standards

All bridges in this fork **must** strictly adhere to the project's code quality standards. This is enforced automatically in CI/CD pipelines and is mandatory for both human contributors and AI agents.

Before submitting or generating any bridge code, ensure it passes the following checks from the project root:

1. **PHP_CodeSniffer (PHPCS)**: Enforces PSR-12 coding standards, strict typing, and modern PHP practices.
   - Configuration: [`phpcs.xml`](https://github.com/LordArrin/rss-bridge/blob/master/phpcs.xml)
   - Command: `vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php`
   - Auto-fix: `vendor/bin/phpcbf --standard=phpcs.xml bridges-v2/YourBridge.php`

2. **PHPStan**: Performs static analysis to catch type errors, dead code, and potential bugs. **Level 5 is the minimum required standard.**
   - Configuration: [`phpstan.neon`](https://github.com/LordArrin/rss-bridge/blob/master/phpstan.neon)
   - Command: `vendor/bin/phpstan analyse bridges-v2/YourBridge.php --level=5`
   - Includes `phpstan-strict-rules` and `phpstan-deprecation-rules`
   - Includes a custom rule `ForbidOldDomApiRule` that **forbids the legacy DOM API** (`DOMDocument`, `DOMNode`, `DOMElement`, `DOMXPath`, ...) and enforces the new `\Dom\*` API (`\Dom\HTMLDocument`, `\Dom\XMLDocument`, `\Dom\Element`, `\Dom\XPath`, ...)

Key PHPCS rules to keep in mind:
- `declare(strict_types=1)` is required in every file
- Only strict comparisons (`===`, `!==`); the house style is explicit boolean checks like `in_array($x, $y, true) === true`
- Short array syntax `[]` only
- Single-quoted strings unless interpolation or escapes are needed
- One space around the `.` concatenation operator
- Max line length: 240 characters
- Forbidden functions: `sizeof`→`count`, `join`→`implode`, `print`→`echo`, `intval`/`floatval`/`strval`→casts, `is_null`→`=== null`, `create_function`→`Closure::fromCallable()`, `utf8_encode`/`utf8_decode`→`mb_convert_encoding`, `returnClientError`/`returnServerError`→`throwClientException`/`throwServerException`, plus all debug (`var_dump`, `print_r`, ...) and process-execution (`exec`, `shell_exec`, ...) functions

Composer scripts are available as shortcuts:

```bash
composer lint       # phpcs on the whole project
composer lint:fix   # phpcbf auto-fix
composer stan       # phpstan (level from phpstan.neon)
composer test       # phpunit
```

**CI behavior** (`.github/workflows/lint-bridges.yml`, runs on pull requests touching `bridges-v2/**`):
- `phpcbf` auto-fixes style issues and commits them back to the PR branch
- `phpcs` errors fail the build; warnings are reported but tolerated
- PHPStan Level 5 runs on the changed bridge files
- Bridge files must **not** have the executable bit set (`git update-index --chmod=-x bridges-v2/YourBridge.php`)

> **⚠️ Note for AI Agents:** When generating or modifying bridge code, you must proactively ensure the output complies with `phpcs.xml` rules (e.g., `declare(strict_types=1)`, proper namespaces, no unused variables) and passes PHPStan Level 5. Fork-specific traps: `getContents()` returns `string|Response` (cast with `(string)` or guard with `is_string()`), `querySelectorAll()` iterates `\Dom\Node` (narrow with `instanceof \Dom\Element` before calling Element methods), and the legacy `DOM*` API is forbidden by `ForbidOldDomApiRule`. Do not output code that will fail these checks.

## Available PHP Extensions

This fork also includes modern PHP extensions that enable advanced features (all preinstalled in the Docker image):

| Extension | Purpose | Use Case |
|---|---|---|
| `tidy` | HTML cleanup | Fix malformed HTML before parsing |
| `sodium` | Cryptography | HMAC signatures, API authentication |
| `igbinary` | Fast serialization | Efficient caching of complex data |
| `msgpack` | Alternative serialization | JSON-like binary format |
| `zstd` | Compression | Compress large cached objects |
| `brotli` | Compression | Alternative compression codec |
| `apcu` | In-memory cache | Ultra-fast local caching |
| `gd` | Image processing | Thumbnails, image manipulation |
| `ffi` | C library integration | Direct calls to native libraries |

## Minimal Bridge

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

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

Save this as `bridges-v2/ExampleBridge.php` and restart the container. The bridge will appear on the main page (see [Testing Your Bridge](#testing-your-bridge) for Docker specifics).

## Required Constants

Every bridge should define these five constants. All must have explicit `public` visibility:

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

`BridgeAbstract` provides fallbacks (`NAME`/`URI` default to `null`, `DESCRIPTION` to `'No description provided'`, `MAINTAINER` to `'No maintainer'`, `CACHE_TIMEOUT` to `3600`), but defining all five explicitly is the convention.

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
            'pattern' => '[A-Za-z0-9_]+',
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

Supported parameter types: `text` (default), `number`, `checkbox`, `list`.

Supported parameter keys:

| Key | Applies to | Description |
|---|---|---|
| `name` | all | Label shown in the UI |
| `type` | all | `text`, `number`, `checkbox`, `list` |
| `required` | all | Reject empty input |
| `defaultValue` | all | Prefilled value |
| `exampleValue` | all | Placeholder shown in the UI |
| `title` | all | Tooltip text |
| `pattern` | `text` | Regex **without delimiters**; validated as `/^pattern$/`, invalid input becomes `null` |
| `values` | `list` | `'Label' => 'value'` map; nested arrays create option groups |

`BridgeAbstract` also ships a ready-made limit parameter definition you can reuse:

```php
'limit' => self::LIMIT,
```

Access parameter values inside `collectData()`. `getInput()` returns `mixed|null`, so always cast explicitly:

```php
$username = (string) $this->getInput('username');
$limit = (int) ($this->getInput('limit') ?: 10);
$includeReplies = (bool) $this->getInput('include_replies');
$category = (string) $this->getInput('category');
```

For `list` parameters, `$this->getKey('category')` returns the display label (array key) of the selected value.

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

If a required option is missing, `loadConfiguration()` throws an exception before `collectData()` runs. Options may also declare a `defaultValue`.

## Fetching and Parsing HTML

Use `getSimpleHTMLDOM()` for one-off HTML fetching. It returns a native `\Dom\HTMLDocument` and throws if the response is empty:

```php
$dom = getSimpleHTMLDOM('https://example.com/posts');
$dom = getSimpleHTMLDOM($url, $headers, $curlOptions);
```

When you need the raw HTTP response (for JSON APIs, binary data, or custom parsing), use `getContents()`:

```php
$html = (string) getContents($url);
$json = (string) getContents($url, ['Accept: application/json']);
$response = getContents($url, [], [], true);  // requests the full Response object
```

> **⚠️ PHPStan trap:** `getContents()` is declared as returning `string|Response`. Passing the result directly into a `string` parameter (e.g. `\Dom\HTMLDocument::createFromString()`) **fails PHPStan Level 5**. In practice `getContents()` returns a string unless `$returnFull = true`, but static analysis needs help. Use one of the verified patterns:
>
> ```php
> // Pattern 1: explicit cast (used by CodebergReleasesBridge)
> $html = (string) getContents($url);
>
> // Pattern 2: is_string() guard (used by quirks/media_embed.php)
> $response = getContents($url, [], [], true);
> if (is_string($response) === true) {
>     throwServerException('Unexpected string response');
> }
> $html = $response->getBody();
> ```

`RSSBridge\Http\Response` API (final class, **no `__toString()`**): `getBody(): string`, `getCode(): int`, `getStatusLine(): string`, `getHeader(string $name, bool $all = false): string|array|null`, `getHeaders(): array`, `withBody(string $body): self`.

### How getContents() Caching Works

- Successful responses (HTTP 200/201/202) are cached for **24 hours** (`86400` seconds), unless `Cache-Control: no-cache/no-store` is present
- Cached responses are revalidated with `ETag` (`If-None-Match`) and `Last-Modified`; freshness honors `Cache-Control: max-age`, `Expires`, and `Age`
- The cache key includes the POST body hash, so different POST payloads are cached separately
- Non-2xx responses (except redirects and 304) throw `HttpException::fromResponse()` — Cloudflare challenge pages are automatically detected and thrown as `CloudFlareException`
- Global settings apply: `[http] timeout` (60s default), `[error] retries`, `[error] max_filesize` (40 MB default)
- **SSRF protection is on by default** (`[http] ssrf_check = true`): requests to internal/reserved IP ranges are blocked, with DNS pinning against rebinding

For sitemaps there is a dedicated helper returning a list of `{loc, lastmod, changefreq, priority}` entries:

```php
$urls = get_sitemap('https://example.com/sitemap.xml');
```

### Caching HTML Manually

`getSimpleHTMLDOMCached()` does not exist in this fork. For longer-lived caching (e.g. full article pages), use the bridge-level cache helpers with an `is_string()` guard (`loadCacheValue()` returns `mixed`):

```php
$cacheKey = 'article_' . md5($articleUrl);
$cachedHtml = $this->loadCacheValue($cacheKey);

if (is_string($cachedHtml) === false || $cachedHtml === '') {
    $articleDom = getSimpleHTMLDOM($articleUrl);
    $cachedHtml = $articleDom->saveHTML() ?: '';
    $this->saveCacheValue($cacheKey, $cachedHtml, 86400);
}

$dom = \Dom\HTMLDocument::createFromString($cachedHtml);
```

### Parsing XML and Atom Feeds

Use `\Dom\XMLDocument` for XML (suppressing libxml warnings is the house pattern):

```php
$feedXml = (string) getContents(self::URI . 'atom.xml');
if ($feedXml === '') {
    throwServerException('Failed to fetch Atom feed');
}

libxml_use_internal_errors(true);
$xml = \Dom\XMLDocument::createFromString($feedXml);
libxml_clear_errors();
libxml_use_internal_errors(false);

foreach ($xml->querySelectorAll('entry') as $entry) {
    // ...
}
```

If your goal is to *expand* an existing feed, prefer [`FeedExpander`](#expanding-existing-feeds-feedexpander) instead of manual XML parsing.

### Selectors

`\Dom\HTMLDocument` uses standard CSS selectors:

```php
// Single element (returns \Dom\Element or null)
$title = $dom->querySelector('h1.title');
$author = $dom->querySelector('article .author a');

// Multiple elements (returns \Dom\NodeList)
foreach ($dom->querySelectorAll('article.post') as $post) {
    // ...
}
```

> **⚠️ PHPStan trap:** `querySelectorAll()` returns a `\Dom\NodeList` whose items are typed `\Dom\Node`, but `querySelector()`/`getAttribute()`/`innerHTML` are members of `\Dom\Element` (via `ParentNode`). Narrow the type before using Element APIs — this is the pattern used by bridges that pass strict checks:
>
> ```php
> foreach ($node->querySelectorAll('p, div') as $element) {
>     if ($element instanceof \Dom\Element === false) {
>         continue;
>     }
>     $style = $element->getAttribute('style') ?? '';
> }
> ```
>
> `querySelector()` (singular) already returns `?\Dom\Element` and needs no guard, only a null check (or `?->`).

`\Dom\NodeList` also supports `->length` and `->item(0)` when you prefer index access.

### Accessing Data

| Property / Method | Available on | Type | Description |
|---|---|---|---|
| `$node->textContent` | `\Dom\Node` | `string` | Plain text, HTML stripped |
| `$node->innerHTML` | `\Dom\Element` | `string` | Inner HTML |
| `$node->outerHTML` | `\Dom\Element` | `string` | Element with inner HTML |
| `$node->getAttribute('href')` | `\Dom\Element` | `?string` | Attribute value |
| `$node->tagName` | `\Dom\Element` | `string` | Tag name (lowercase) |
| `$node->parentElement` | `\Dom\Node` | `?\Dom\Element` | Parent element |
| `$node->childNodes` | `\Dom\Node` | `\Dom\NodeList` | Children |

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

## HTML Cleanup with Tidy

Many websites generate malformed HTML (unclosed tags, broken attributes) that can break `\Dom\HTMLDocument`. Use the `tidy` extension to fix these issues:

```php
$html = (string) getContents($url);
$tidy = new \tidy();
$tidy->parseString($html, [
    'clean' => true,
    'output-xhtml' => true,
    'wrap' => 0,
    'drop-empty-elements' => false,
], 'utf8');
$tidy->cleanRepair();

$dom = \Dom\HTMLDocument::createFromString((string) $tidy);
```

There is no `tidy_html()` helper in this fork — use the `\tidy` class directly as shown above.

**When to use tidy:**

- Site returns HTML with parsing errors
- `\Dom\HTMLDocument` throws exceptions on valid pages
- You need to normalize HTML from multiple sources

**Performance note:** `tidy` adds ~5-10ms overhead per request. Use only when necessary.

## Utility Functions (`quirks/`)

The `quirks/` directory contains battle-tested helpers. All are loaded globally via Composer `files` autoload — no import needed.

### HTML Generation & Sanitization (`quirks/html.php`)

```php
// Escape user input (prevents XSS): htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE
$safe = e($userInput);

// Semantic no-op marker: "this string is intentionally not escaped"
$trusted = raw($safeHtml);

// Truncate long strings (defaults: length 150, marker '...')
$short = truncate($longText, 200, '...');

// Generate HTML tags with attribute whitelist validation
$div = html_tag('div', 'Hello', ['class' => 'greeting']);
$input = html_input(['type' => 'text', 'name' => 'q', 'value' => 'search']);
$option = html_option('United States', 'us', true);

// Neutralize dangerous tags (script, iframe, object, embed, applet, svg,
// math, link, style, meta, base), strip on* event handlers and
// javascript: URLs while keeping visible text
$clean = break_annoying_html_tags($html);

// DOM-level sanitization: strip all attributes except a strict whitelist,
// validate href/src schemes, sanitize inline CSS
sanitize_dom_element_attributes($element, ['a' => ['href', 'title']]);

// URI scheme validation (http, https, mailto, ftp, data:image/*; anchors ok)
$ok = is_safe_uri($uri);

// Remove expression()/behavior:/@import/javascript: from inline CSS
$css = sanitize_inline_css($rawCss);

// Strip invisible Unicode characters (zero-width spaces, Bidi markers, non-breaking spaces,
// ASCII control chars) that break text matching, title generation, or cause visual glitches.
$cleanText = strip_invisible_unicode($rawText);

// Truncate HTML to a max number of VISIBLE characters without breaking tags.
// Unlike mb_substr(), this tokenizes the HTML, counts only text nodes, and safely
// closes any open tags (e.g., <a>, <b>) to prevent layout breakage in feed readers.
$summary = truncate_html_preserve_tags($articleHtml, 250, '...');

// Render a poll as an ASCII progress bar list (safe and readable in all RSS readers).
// Pass 'blocks' as $barStyle to use Unicode block characters (█░) instead of ASCII ([#...]).
// $options must be an array of ['text' => string, 'votes' => int, 'percent' => float].
$pollHtml = render_ascii_poll($pollOptions, $totalVoters, 'ascii');
```

`html_tag()` only allows these attributes: `type`, `id`, `name`, `value`, `placeholder`, `title`, `pattern`, `class`, `for`, `oncontextmenu`, `data-for`, `formtarget` (string), and `checked`, `required`, `selected` (boolean). Anything else throws — this prevents injection of event handlers like `onclick`.

### String Extraction (`quirks/extract.php`)

Extract data from HTML, inline JavaScript, or CSS styles:

```php
// Extract text between delimiters (returns false if start delimiter not found)
$data = extractFromDelimiters($html, 'window.data = ', ';');

// Remove sections between delimiters
$clean = stripWithDelimiters($html, '<script>', '</script>');

// Remove recursive HTML sections (handles nested tags of the same name)
$clean = stripRecursiveHTMLSection($html, 'div', '<div class="ads">');

// Extract a background image URL from an inline CSS style attribute.
// Useful for modern SPAs using <div style="background-image: url('...')"> instead of <img>.
// Returns null if no valid URL is found.
$imageUrl = extract_css_background_image($element->getAttribute('style') ?? '');

// Extract a global JSON variable from a SPA HTML page (e.g., ytInitialData).
// Safely decodes the JSON payload into an associative array, returning null on failure.
// This avoids writing custom regex for <script> tags and handles strict JSON decoding.
$spaData = extract_spa_json_variable($html, 'ytInitialData');
```

> **⚠️ Note for AI Agents:** When dealing with SPA sites (YouTube, Twitter, Boosty, etc.), **always** use `extract_spa_json_variable()` instead of trying to parse `<script>` tags with regex manually. It handles edge cases like minified code and strict JSON validation (`JSON_THROW_ON_ERROR`). For CSS-heavy layouts, use `extract_css_background_image()` instead of writing brittle regex for `url(...)`.

### Srcset Parsing (`quirks/srcset.php`)

```php
// Parse srcset attribute into size => URL map
$entries = parseSrcset('image-320w.jpg 320w, image-1024w.jpg 1024w');
// => ['320w' => 'image-320w.jpg', '1024w' => 'image-1024w.jpg']

// Get the largest image URL (null if no valid entries)
$largest = parseSrcsetLargestImageUrl($srcset);
```

### JSON Path Traversal (`quirks/json_path.php`)

```php
// Safely traverse nested arrays/objects; returns null if any step is missing
$title = read_json_path($data, 'response', 'items', 0, 'title');
```

### SEO Metadata (`quirks/seo.php`)

Extract Open Graph, Twitter Cards, and JSON-LD metadata. Accepts **either** a raw HTML string **or** a parsed `\Dom\HTMLDocument`:

```php
$metadata = html_find_seo_metadata($html);
```

Returns an array containing **only the keys that were found**, among: `uri`, `title`, `content`, `timestamp` (int, already parsed via `strtotime`), `enclosures` (list of strings), `author`.

```php
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
// Convert Markdown to HTML via Parsedown.
// Supported options: breaksEnabled, markupEscaped, urlsLinked.
// An unknown option throws \InvalidArgumentException.
$html = markdownToHtml($markdown, ['breaksEnabled' => true]);

// Generate YouTube embed (iframe) or a clickable WebP/JPEG thumbnail,
// controlled by [youtube] iframe/nocookie in config.ini.php.
// Returns '' if no valid video ID is found.
$embed = handleYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

// Render a Draft.js JSON content block into safe HTML.
// Decodes React-based editor payloads (used by Boosty, Medium, etc.) where styling
// is applied via offset ranges in UTF-16LE code units. Correctly handles surrogate
// pairs (emojis) without splitting them in half. Returns escaped plain text on parse failure.
$richText = render_draftjs_to_html($draftJsonString);
```

### Text Filtering (`quirks/filter.php`)

Filter feed items based on complex user-defined keyword rules. Loaded globally via Composer `files` autoload.

```php
// Check if a string matches a set of keyword rules.
// - Commas act as logical OR: "word1, word2" matches if EITHER is present.
// - Plus signs act as logical AND: "word1+word2" matches only if BOTH are present.
// - Matching is case-insensitive.
$includeRules = 'apple, microsoft+windows, linux+ubuntu';
$excludeRules = 'ads+sponsored';

if (matches_keyword_rules($itemTitle, $includeRules) === true &&
    matches_keyword_rules($itemTitle, $excludeRules) === false) {
    // Keep the item
}
```

> **⚠️ Note for AI Agents:** When implementing user-configurable inclusion/exclusion filters in a bridge's `PARAMETERS`, **always** delegate the matching logic to `matches_keyword_rules()`. Do not write custom `str_contains()` loops, as they usually miss the logical AND (`+`) operator and edge cases with empty strings.

### Binary Embedding (`quirks/media_embed.php`)

Embed remote images and media as data URIs:

```php
// Main entry point. Returns the data URI, or the ORIGINAL URL as fallback
// on fetch failure or when $maxSize is exceeded. Pass $this->cache for
// persistent caching (key: 'embed_' . md5($url), default TTL 7 days).
$dataUri = media_embed_url_to_data_uri(
    $imageUrl,
    $this->cache,                     // ?CacheInterface
    604800,                           // cache TTL in seconds
    media_embed_parse_size('2m'),     // max size in bytes, 0 = unlimited
    null,                             // ?string proxy profile name
    $this->logger,                    // ?Logger
    1                                 // retry attempts
);

// Low-level fetch with retries/backoff; returns ['body' => ..., 'type' => ...] or null
$media = media_embed_fetch_binary($url);

// MIME detection
$mime = media_embed_mime_from_url($url);          // '' if unknown
$mime = media_embed_mime_from_extension('png');   // '' if unknown

// Human-readable size parsing: '10m', '1.5g', '512k', '2GB' => bytes (0 if unparseable)
$bytes = media_embed_parse_size('1.5MB');
```

Note the signatures: the second parameter of `media_embed_url_to_data_uri()` is a `?CacheInterface` (not an options array), and `media_embed_fetch_binary()` returns `array{body: string, type: string}|null` (not a raw string).

## Working with JSON APIs

For JSON-based sites, skip HTML entirely. The `Json` class lives in the `RSSBridge\Utils` namespace — import it explicitly:

```php
use RSSBridge\Utils\Json;
```

```php
public function collectData(): void
{
    $url = 'https://api.example.com/posts';
    $data = Json::decode((string) getContents($url, ['Accept: application/json']));

    if (!is_array($data)) {
        throwServerException('Invalid JSON response');
    }

    foreach ($data['items'] ?? [] as $entry) {
        $this->items[] = [
            'title' => $entry['title'] ?? 'Untitled',
            'uri' => $entry['url'] ?? self::URI,
            'content' => $entry['body'] ?? '',
            'timestamp' => isset($entry['date']) ? strtotime($entry['date']) : time(),
            'uid' => (string) ($entry['id'] ?? uniqid()),
        ];
    }
}
```

- `Json::decode(string $json, bool $assoc = true): mixed` and `Json::encode(mixed $value, bool $pretty = true, bool $asciiSafe = false): string` both throw `\JsonException` on invalid input (`JSON_THROW_ON_ERROR`)
- Note the `(string)` cast on `getContents()` — required for PHPStan Level 5
- An equally valid pattern used by bridges: `json_decode((string) getContents($url, $headers), true, 512, JSON_THROW_ON_ERROR)`
- For deeply nested payloads, combine with `read_json_path()` (see [quirks](#json-path-traversal-quirksjson_pathphp))

## APCu In-Memory Cache

For ultra-fast local caching (faster than memcached), use APCu. Data persists across requests within the same container:

```php
public function collectData(): void
{
    $cacheKey = 'api_config_' . $this->getInput('site');

    // Try APCu first (fastest, local to this container)
    $config = apcu_fetch($cacheKey, $success);

    if ($success === false) {
        // Fetch from API
        $config = $this->fetchConfigFromAPI();

        // Cache for 5 minutes (APCu is local, so use shorter TTL)
        apcu_store($cacheKey, $config, 300);
    }

    // Use $config...
}
```

**APCu vs Memcached:**

- **APCu**: In-memory, local to container, ~1µs access time, lost on container restart
- **Memcached**: Network-based, shared across containers, ~100µs access time, persistent (this fork's default `[cache] type` is `memcached`)

**Best practice:** Use APCu for frequently accessed, rarely changing data (config, metadata). Use memcached for shared data across multiple instances.

## Advanced Compression with Zstd

For caching large objects (full HTML pages, JSON responses), compress with `zstd` to save memory:

```php
public function collectData(): void
{
    $cacheKey = 'full_page_' . md5($url);

    $cached = $this->cache->get($cacheKey);
    if (is_string($cached) === true && $cached !== '') {
        $uncompressed = zstd_uncompress($cached);
        $html = is_string($uncompressed) ? $uncompressed : '';
    } else {
        $html = (string) getContents($url);

        // Compress before storing (level 3 = good balance)
        $this->cache->set($cacheKey, zstd_compress($html, 3), 86400);
    }

    if ($html === '') {
        throwServerException('Failed to load page');
    }

    $dom = \Dom\HTMLDocument::createFromString($html);
    // ... process
}
```

**Compression levels:** 1 (fastest) to 22 (smallest). Level 3-5 is optimal for most use cases.

**When to use:**

- Caching full HTML pages (>50KB)
- Large JSON API responses
- Binary data (images, archives)

## Error Handling

All exception classes and helper functions live in the **global namespace** (`lib/exceptions.php`) — no imports needed:

| Class | Extends | Default code |
|---|---|---|
| `HttpException` | `\Exception` | — (carries `?Response $response` and `?int $retryAfter`) |
| `CloudFlareException` (final) | `HttpException` | — |
| `ClientException` | `HttpException` | 400 |
| `ServerException` | `HttpException` | 500 |
| `RateLimitException` | `ClientException` | 429 |

Use the helper functions inside `collectData()` (all return `never`):

```php
// User provided bad input (4xx)
throwClientException('Invalid user ID');            // optional 2nd arg: status code

// Server-side or upstream issue (5xx)
throwServerException('Failed to fetch data from API');

// Rate limit hit upstream (429, Retry-After hint)
throwRateLimitException('API rate limit exceeded'); // optional 2nd arg: retryAfter seconds (default 3600)

// HTTP-level error with a specific code — note: GLOBAL class, not namespaced
throw new \HttpException('Not Found', 404);
```

`getContents()` throws `HttpException::fromResponse()` automatically on non-2xx responses; Cloudflare challenge pages are detected and raised as `CloudFlareException`. The `Retry-After` header is parsed into `HttpException::$retryAfter`.

All exceptions are caught by the framework and rendered according to the `[error] output` setting in `config.ini.php` (`feed`, `http`, or `none`).

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

Additional custom keys are allowed — `FeedItem::fromArray()` stores unknown keys in a `misc` bag and round-trips them back via `toArray()`. This is how `FeedExpander` bridges pass extra data (e.g. `apod` metadata) between `collectExpandableDatas()` and `parseItem()`.

## Caching

HTTP responses are cached automatically by `getContents()` for up to **24 hours**, with conditional revalidation via `ETag`/`Last-Modified` and respect for `Cache-Control` headers. For caching parsed or processed data, use the bridge-level cache helpers:

```php
$cacheKey = 'example_' . md5($url);
$cached = $this->loadCacheValue($cacheKey);

if ($cached !== null) {
    // loadCacheValue() returns mixed — narrow before use (PHPStan)
}

// ... fetch and process data ...

$this->saveCacheValue($cacheKey, $processedData, 3600); // default TTL: 86400
```

`loadCacheValue()`/`saveCacheValue()` automatically prefix the key with the bridge's short name (e.g. `ExampleBridge_example_...`), so keys never collide between bridges.

Alternatively, access the cache backend directly (no prefixing) and the logger:

```php
$this->cache->get($cacheKey);
$this->cache->set($cacheKey, $data, $ttl);
$this->logger->warning('Upstream returned unexpected payload');
```

Both `$this->cache` and `$this->logger` are injected by `BridgeFactory` through the constructor. Do not define your own constructor unless you forward `($cache, $logger)` to `parent::__construct()`.

**Cache backend priority:**

1. APCu (if enabled, fastest)
2. Memcached (shared across instances; the default in `config.default.ini.php`)
3. File cache (fallback)

## Proxy Profiles

For Cloudflare-protected sites or sites requiring specific IPs, use the proxy system:

```php
// Fetch raw HTML via a named proxy profile (throws ClientException on failure)
$html = getProtectedContents($url, 'flaresolverr');

// Fetch and parse into \Dom\HTMLDocument
$dom = getProtectedSimpleHTMLDOM($url, 'tgws');

// Fetch binary content (images, videos).
// Returns ['body' => string, 'type' => string] or null on failure — NOT a raw string.
$binary = getProtectedBinary($url, 'direct');
if ($binary !== null) {
    $bytes = $binary['body'];
    $mime = $binary['type'];
}
```

All three accept an optional third parameter `array $options = []`.

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

`proxy_profile_direct` and `proxy_profile_tgws` are preconfigured in `config.default.ini.php`. If a profile is referenced but not defined, `getProtectedContents()` throws a `ClientException` with a hint about the missing configuration.

## Relative URLs

For individual URLs, use `urljoin()` from the `busybee/urljoin` package. Since bridges are namespaced, import the function explicitly (house style):

```php
use function urljoin;
```

```php
$absolute = urljoin('https://example.com/posts/', '/img/photo.jpg');
// => 'https://example.com/img/photo.jpg'
```

For processing many URLs inside an HTML fragment, combine `\Dom\HTMLDocument` selectors with `urljoin()` in a loop:

```php
$dom = getSimpleHTMLDOM($articleUrl);
foreach ($dom->querySelectorAll('img[src]') as $img) {
    if ($img instanceof \Dom\Element === false) {
        continue;
    }
    $src = $img->getAttribute('src');
    if ($src !== null && $src !== '' && str_starts_with($src, 'http') === false) {
        $img->setAttribute('src', urljoin($articleUrl, $src));
    }
}
$html = $dom->saveHTML();
```

## Expanding Existing Feeds (FeedExpander)

If a site already publishes an RSS/Atom feed and you only need to enrich its items (e.g. fetch full article bodies), extend `RSSBridge\FeedExpander` instead of parsing XML manually:

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class MyBlogExpanderBridge extends FeedExpander
{
    public const NAME = 'My Blog (expanded)';
    public const URI = 'https://blog.example.com/';
    public const DESCRIPTION = 'Full-text version of the official feed';
    public const MAINTAINER = 'yourname';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $this->collectExpandableDatas(self::URI . 'feed.xml', 10);
    }

    protected function parseItem(array $item): array|false
    {
        // Enrich the item, or return false to drop it from the feed
        $dom = getSimpleHTMLDOM($item['uri']);
        $item['content'] = $dom->querySelector('article')?->innerHTML ?? $item['content'];
        return $item;
    }
}
```

- `collectExpandableDatas(string $url, int $maxItems = -1, array $headers = []): void` fetches and parses the feed (`-1` means up to 999 items)
- `parseItem(array $item): array|false` is called for every item; return `false` or `[]` to drop it
- `getName()`, `getURI()`, and `getIcon()` are automatically taken from the parsed feed
- Custom keys survive the round-trip through the `misc` bag (see [Feed Item Structure](#feed-item-structure))

## Namespaced Core Classes

Core classes live in the `RSSBridge` namespace tree (loaded via Composer classmap / PSR-4). Import them explicitly when needed:

```php
use RSSBridge\BridgeAbstract;          // required — every bridge extends it
use RSSBridge\FeedExpander;            // base class for feed-expanding bridges
use RSSBridge\Configuration;           // static access to config.ini.php
use RSSBridge\Utils\Json;              // JSON encode/decode (throws \JsonException)
use RSSBridge\Utils\Url;               // URL/MIME/formatting helpers
use RSSBridge\Http\Response;           // HTTP response object (no __toString)
use RSSBridge\Caches\CacheInterface;   // type hints for cache parameters
use RSSBridge\Logger\Logger;           // type hints for logger parameters
```

**Exceptions are the opposite:** `HttpException`, `ClientException`, `ServerException`, `RateLimitException`, `CloudFlareException` and the `throwClientException()`/`throwServerException()`/`throwRateLimitException()` helpers are all in the **global namespace** — do **not** import them. The same applies to `getContents()`, `getSimpleHTMLDOM()`, `getProtected*()`, `get_sitemap()` and all `quirks/` functions.

`RSSBridge\FeedItem` and `RSSBridge\FeedParser` exist but are framework internals — bridges normally work with plain arrays.

## Running Quality Checks

From the repository root:

```bash
# Lint your bridge
vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php

# Auto-fix style issues
vendor/bin/phpcbf --standard=phpcs.xml bridges-v2/YourBridge.php

# Static analysis (Level 5 is mandatory; phpstan.neon already sets it)
vendor/bin/phpstan analyse bridges-v2/YourBridge.php --level=5
```

Or use the Composer shortcuts: `composer lint`, `composer lint:fix`, `composer stan`.

From inside the Docker container (the Alpine image exposes PHP as `php85`; dev dependencies are installed in the image):

```bash
docker exec rss-bridge php85 /app/vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php
```

## Testing Your Bridge

1. Place the bridge file in `bridges-v2/` — this is the **only** directory `BridgeFactory` scans (non-recursively), and the file name must match `*Bridge.php`. There is no `config/bridges-v2/` loading path.
2. Make sure the bridge is enabled: `config.default.ini.php` ships with `[system] enabled_bridges[] = *` (all bridges enabled). If your `config.ini.php` lists bridges explicitly, add yours (short name, e.g. `MyBridge` or `My`).
3. For Docker deployments the image ships with built-in bridges baked in; to test a custom bridge without rebuilding, bind-mount the file into the container:

   ```yaml
   volumes:
     - /home/user/rssbridge/config:/config
     - ./MyBridge.php:/app/bridges-v2/MyBridge.php
   ```

4. Restart the container: `docker restart rss-bridge`.
5. Open `http://localhost:3000/` in a browser (the compose file maps port 3000 to the container's port 80).
6. Find your bridge, fill in parameters, and click **Generate feed**.
7. Inspect the resulting feed for correctness.

If the bridge fails to load, check the container logs:

```bash
docker logs rss-bridge | grep -i "YourBridge"
```

Bridges are first loaded inside a sandboxed PHP subprocess (`SafeBridgeLoader` / `BridgeFactory::loadBridgeInSandbox()`), so fatal compile errors (e.g. signature mismatches with the parent class) are reported with the exact file and line instead of crashing the whole application.

Finally, make sure the file is not marked executable in git — CI rejects executable bridge files:

```bash
git update-index --chmod=-x bridges-v2/YourBridge.php
```

## Performance Optimization Tips

### 1. Use Efficient Serialization
For caching large datasets, use `igbinary_serialize()` instead of native `serialize()`:
```php
$this->cache->set($key, igbinary_serialize($data), $ttl);
```

### 2. Compress Large Objects
Use `zstd_compress()` for HTML pages or JSON responses >50KB:
```php
$compressed = zstd_compress($html, 3); // Level 3 = good balance
```

### 3. Leverage APCu for Hot Data
Cache frequently accessed config or metadata in APCu (use the `$success` flag — `apcu_fetch()` returns `false` on miss):
```php
$config = apcu_fetch($key, $ok);
if ($ok === false) {
    $config = $this->fetchConfig();
    apcu_store($key, $config, 300);
}
```

### 4. Clean Malformed HTML
Use `tidy` for sites with broken HTML to prevent parsing errors:
```php
$tidy = new \tidy();
$tidy->parseString($html, ['clean' => true], 'utf8');
$tidy->cleanRepair();
```

### 5. Batch API Requests
When fetching multiple items, use parallel requests or batch endpoints when available.

### 6. Use Appropriate Cache TTLs
- Static content: 24-48 hours
- Frequently updated: 15-60 minutes
- Real-time data: 1-5 minutes

## Checklist Before Committing

- [ ] File is in `bridges-v2/` and named `*Bridge.php`
- [ ] File is **not** executable in git (`git update-index --chmod=-x` if needed)
- [ ] `declare(strict_types=1)` at the top
- [ ] `namespace RSSBridge\Bridges;` declared
- [ ] Class is `final` and extends `BridgeAbstract` (or `FeedExpander`)
- [ ] All five required constants defined with `public const`
- [ ] No `simple_html_dom` calls (`str_get_html()`, `->find()`, `->plaintext`) and no legacy `DOM*` classes (`DOMDocument`, `DOMXPath`, ...) — forbidden by `ForbidOldDomApiRule`
- [ ] Uses `getSimpleHTMLDOM()`, `\Dom\HTMLDocument`, or `\Dom\XMLDocument` for parsing
- [ ] `getContents()` results cast to `(string)` or guarded with `is_string()` (PHPStan Level 5)
- [ ] `querySelectorAll()` items narrowed with `instanceof \Dom\Element` before Element methods
- [ ] Null-safe operator `?->` used for optional elements
- [ ] Exception helpers (`throwClientException`, ...) used from the **global namespace** — no imports
- [ ] `Json` imported as `use RSSBridge\Utils\Json;` if used
- [ ] Relative URLs handled via `urljoin()` (with `use function urljoin;`)
- [ ] Code passes `phpcs` checks (see [`phpcs.xml`](https://github.com/LordArrin/rss-bridge/blob/master/phpcs.xml))
- [ ] Code passes `phpstan` analysis at Level 5 (see [`phpstan.neon`](https://github.com/LordArrin/rss-bridge/blob/master/phpstan.neon))
- [ ] Bridge tested with real data in Docker and enabled via `[system] enabled_bridges`
- [ ] Malformed HTML sites use `tidy` cleanup
- [ ] Large cached objects use `igbinary` or `zstd` compression
- [ ] API authentication uses `sodium` for signatures
- [ ] APCu used for frequently accessed local data
- [ ] Used built-in `quirks/` helpers (e.g., `truncate_html_preserve_tags`, `extract_spa_json_variable`, `matches_keyword_rules`) instead of writing custom regex or substring logic

## Full Reference Example

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class BlogBridge extends BridgeAbstract
{
    public const NAME = 'Example Blog';
    public const URI = 'https://blog.example.com/';
    public const DESCRIPTION = 'Latest posts from Example Blog';
    public const MAINTAINER = 'yourname';
    public const CACHE_TIMEOUT = 1800;

    private const ARTICLE_CACHE_TTL = 86400;

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
        $category = (string) $this->getInput('category');
        $limit = (int) ($this->getInput('limit') ?: 20);

        $url = $category !== ''
            ? self::URI . 'category/' . rawurlencode($category) . '/'
            : self::URI;

        $html = (string) getContents($url);
        if ($html === '') {
            throwServerException('Empty response from ' . $url);
        }

        $tidy = new \tidy();
        $tidy->parseString($html, [
            'clean' => true,
            'output-xhtml' => true,
            'wrap' => 0,
        ], 'utf8');
        $tidy->cleanRepair();

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString((string) $tidy);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $count = 0;
        foreach ($dom->querySelectorAll('article.post') as $post) {
            if ($post instanceof \Dom\Element === false) {
                continue;
            }
            if ($count >= $limit) {
                break;
            }

            $link = $post->querySelector('a.post-title');
            if ($link === null) {
                continue;
            }

            $uri = $link->getAttribute('href');
            // Use strip_invisible_unicode to clean up weird formatting characters from titles
            $title = strip_invisible_unicode(trim($link->textContent));
            if ($uri === null || $uri === '') {
                continue;
            }

            $articleUrl = urljoin(self::URI, $uri);

            $cacheKey = 'article_' . md5($articleUrl);
            $articleHtml = $this->loadCacheValue($cacheKey);
            if (is_string($articleHtml) === false || $articleHtml === '') {
                $articleDom = getSimpleHTMLDOM($articleUrl);
                $articleHtml = $articleDom->querySelector('.entry-content')?->innerHTML ?? '';
                $articleHtml = break_annoying_html_tags($articleHtml);
                $this->saveCacheValue($cacheKey, $articleHtml, self::ARTICLE_CACHE_TTL);
            }

            $metadata = html_find_seo_metadata($articleHtml);

            $dateStr = $post->querySelector('time')?->getAttribute('datetime');
            $timestamp = $dateStr !== null ? strtotime($dateStr) : false;

            $authorName = $post->querySelector('.author-name')?->textContent;
            $author = $authorName !== null && trim($authorName) !== ''
                ? trim($authorName)
                : (string) ($metadata['author'] ?? '');

            $this->items[] = [
                'title' => $title,
                'uri' => $articleUrl,
                // Use truncate_html_preserve_tags for a safe summary if full content is missing
                'content' => $articleHtml !== ''
                    ? $articleHtml
                    : truncate_html_preserve_tags($metadata['content'] ?? '', 300),
                'timestamp' => $timestamp !== false
                    ? $timestamp
                    : (int) ($metadata['timestamp'] ?? time()),
                'author' => $author !== '' ? $author : null,
                'uid' => $articleUrl,
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