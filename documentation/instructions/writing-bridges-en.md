# Writing Bridges for LordArrin's RSS-Bridge Fork

This guide covers bridge development for this fork. It assumes familiarity with the upstream [RSS-Bridge](https://github.com/RSS-Bridge/rss-bridge) project and focuses on what is different here: PHP 8.6 with strict types, the native `\Dom\HTMLDocument` API, PSR-4 namespacing, the `quirks/` utility library, and the FreshRSS-compatible HTTP cache model.

## Key Differences from Upstream

| Feature | Upstream RSS-Bridge | This Fork |
|---|---|---|
| PHP version | 7.4+ | 8.6+ |
| Strict types | Optional | Required (`declare(strict_types=1)`) |
| HTML parser | `simple_html_dom` (embedded) | `\Dom\HTMLDocument` (PHP 8.4+) |
| HTML cleanup | Manual | Native `parseHtmlDocument()` helper; optional `tidy` for severe cases |
| Bridge location | `bridges/` (global namespace) | `bridges-v2/` with `RSSBridge\Bridges` namespace |
| HTML utilities | Inline in each bridge | Centralized in `quirks/` directory |
| Markdown | Embedded Parsedown | `erusev/parsedown` via Composer |
| URL joining | Embedded | `busybee/urljoin` via Composer |
| Bridge loading | Direct `include` | Sandboxed loading via `SafeBridgeLoader`  |
| Configuration | INI files only | Environment variables (system) + `.bridges` (tokens) |

Legacy `bridges/` directory is not supported. All bridges live in `bridges-v2/`.

## Code Quality and Standards

All bridges in this fork **must** strictly adhere to the project's code quality standards. This is enforced automatically in CI/CD pipelines and is mandatory for both human contributors and AI agents.

Before submitting or generating any bridge code, ensure it passes the following checks from the project root:

1. **PHP_CodeSniffer (PHPCS)**: Enforces PSR-12 coding standards, strict typing, and modern PHP practices.
   - Configuration: [`phpcs.xml`](https://github.com/LordArrin/rss-bridge/blob/master/phpcs.xml)
   - Command: `vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php`
   - Auto-fix: `vendor/bin/phpcbf --standard=phpcs.xml bridges-v2/YourBridge.php`

2. **PHPStan**: Performs static analysis to catch type errors, dead code, and potential bugs. **Level 8 is the minimum required standard.**
   - Configuration: [`phpstan.neon`](https://github.com/LordArrin/rss-bridge/blob/master/phpstan.neon)
   - Command: `vendor/bin/phpstan analyse bridges-v2/YourBridge.php`
   - Includes `phpstan-strict-rules` and `phpstan-deprecation-rules`
   - Three custom rules are shipped:
     - `ForbidOldDomApiRule` — forbids the legacy DOM API (`DOMDocument`, `DOMNode`, `DOMElement`, `DOMXPath`, ...) and enforces the new `\Dom\*` API.
     - `Utf8EncodingRule` — rejects files that are not valid UTF-8 (raw CP1251/CP1252 bytes in literals or comments) and shows the likely single-byte decoding.
     - `DomTagNameComparisonRule` (identifier `dom.tagNameLowercaseComparison`) — forbids case-sensitive comparisons against `\Dom\Element::$tagName` (which is UPPERCASE for HTML documents in PHP 8.4+) via `===`, `==`, `!==`, `!=`, `in_array`-needle, or `match` arms; use `$element->localName` instead. It exempts `FeedExpander` subclasses (feed parsers don't go through HTML selectors) and files that contain `\Dom\XMLDocument` (where `tagName` preserves original case), and it does not track variable indirection — when in doubt, compare `localName`.

Key PHPCS rules to keep in mind:
- `declare(strict_types=1)` is required in every file
- Only strict comparisons (`===`, `!==`); the house style is explicit boolean checks like `in_array($x, $y, true) === true`
- Short array syntax `[]` only
- Single-quoted strings unless interpolation or escapes are needed
- One space around the `.` concatenation operator
- Max line length: 240 characters (comments are **ignored**, so long URLs in docblocks do not fail the check)
- Forbidden functions: `sizeof`→`count`, `join`→`implode`, `print`→`echo`, `intval`/`floatval`/`strval`→casts, `is_null`→`=== null`, `create_function`→`Closure::fromCallable()`, `utf8_encode`/`utf8_decode`→`mb_convert_encoding`, `returnClientError`/`returnServerError`→`throwClientException`/`throwServerException`, plus all debug (`var_dump`, `print_r`, ...) and process-execution (`exec`, `shell_exec`, ...) functions

Composer scripts are available as shortcuts:

```bash
composer lint        # phpcs on the whole project
composer lint:fix    # phpcbf auto-fix
composer lint:compat # PHPCompatibility 
composer stan        # phpstan (level from phpstan.neon)
```

> **Note:** For a load-time smoke test of every registered bridge (instantiation via `SafeBridgeLoader`, reporting broken stubs), run `bin/test-bridges`. It does not fetch live data; validate selectors against a real page separately.

**CI behavior** (`.github/workflows/lint-bridges.yml`, runs on pull requests touching `bridges-v2/**`, `phpcs.xml`, `phpstan.neon`, the workflow file itself, or `rules/**`):
- `phpcbf` auto-fixes style issues and commits them back to the PR branch
- `phpcs` errors fail the build (warning severity 8 — warnings are also reported in CI, though locally `composer lint` hides them)
- PHPStan Level 8 runs on the changed bridge files
- Bridge files must **not** have the executable bit set (`git update-index --chmod=-x bridges-v2/YourBridge.php`)

> **⚠️ Note for AI Agents:** When generating or modifying bridge code, you must proactively ensure the output complies with `phpcs.xml` rules (e.g., `declare(strict_types=1)`, proper namespaces, no unused variables) and passes PHPStan Level 8 including all three custom rules. Fork-specific traps: `getContents()` returns `string` by default and `Response` only when `$returnFull = true` (a `(string)` cast is optional, not required); `foreach` over `querySelectorAll()` already yields `\Dom\Element` (an `instanceof` guard there is redundant, not an error); use `saveHtml()` (not `saveHTML()`); avoid the short ternary `?:` and `?->prop ?? $x` (write `->prop ?? $x`); keep ternaries on one line; files must be valid UTF-8 (no raw CP1251/CP1252 bytes); `tagName` is UPPERCASE in HTML — compare via `localName`; and the legacy `DOM*` API is forbidden by `ForbidOldDomApiRule`. Do not output code that will fail these checks.

## Available PHP Extensions

This fork also includes modern PHP extensions that enable advanced features (all preinstalled in the Docker image):

| Extension | Purpose | Use Case |
|---|---|---|
| `tidy` | HTML cleanup | Fix malformed HTML before parsing |
| `sodium` | Cryptography | HMAC signatures, API authentication |
| `msgpack` | Alternative serialization | JSON-like binary format |
| `zstd` | Compression | Compress large cached objects |
| `brotli` | Compression | Alternative compression codec |
| `apcu` | In-memory cache | Ultra-fast local caching |
| `gd` | Image processing | Thumbnails, image manipulation |

> FFI is intentionally not built (no native-call attack surface). `igbinary` was removed — use `msgpack` for compact binary serialization.

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
        $title = $dom->querySelector('h1')->textContent ?? 'Untitled';

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
- `CACHE_TIMEOUT` — default cache TTL in seconds (used as the feed `max-age` and the bridge-cache TTL)

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

> **Gotcha:** `number` is validated via `FILTER_VALIDATE_INT` — only integers are accepted. A value like `"1.5"` becomes `null`, which fails `required` and raises a `ClientException`. Use `text` and parse yourself if you need floats.

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
$limit = (int) ($this->getInput('limit') ?? 10);
$includeReplies = (bool) $this->getInput('include_replies');
$category = (string) $this->getInput('category');
```

Use `??` (not the short ternary `?:`, which PHPStan forbids) so an explicit `0` is preserved; if `0` should fall back to the default, write an explicit `if`.

For `list` parameters, `$this->getKey('category')` returns the display label (array key) of the selected value, or `null` when nothing is selected.

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

For API tokens, cookies, and other sensitive values, define them in `CONFIGURATION` and set the actual values in `/config/.bridges`:

```php
public const CONFIGURATION = [
    'api_token' => ['required' => true],
    'session_cookie' => ['required' => false],
];
```

In `/config/.bridges` (mounted volume):

```ini
[ExampleBridge]
api_token = "your-token-here"
session_cookie = "abc123"
```

> **Note:** The container automatically copies `/config/.bridges` to `/app/.bridges` at startup with correct permissions for PHP-FPM. Default values are provided in `.bridges.default` (baked into the image).

Access values in the bridge:

```php
$token = $this->getOption('api_token');
$cookie = $this->getOption('session_cookie');
```

`Configuration` lowercases section and key names, coerces the strings `true`/`false`/`null`/`yes`/`no`/`on`/`off` to their PHP counterparts, but **numbers remain strings** — always cast with `(int)` / `(float)` when needed.

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

> **ℹ️ PHPStan note:** `getContents()` has a conditional return type: `string` by default, `RSSBridge\Http\Response` when the fourth argument `$returnFull` is `true`. PHPStan resolves this, so a plain `getContents($url)` is already typed `string` and can be passed straight into a `string` parameter — no cast is required for Level 8. The `(string)` cast remains the house style (explicit and harmless). With `$returnFull = true` the result is a `Response` (no `__toString()`), so read the body via `getBody()`:
>
> ```php
> // Default call: already typed string (cast optional, kept as house style)
> $html = (string) getContents($url);
>
> // Full response: typed Response — read the body explicitly
> $response = getContents($url, [], [], true);
> $html = $response->getBody();
> ```

`RSSBridge\Http\Response` API (final class, **no `__toString()`**): `getBody(): string`, `getCode(): int`, `getStatusLine(): string`, `getHeader(string $name, bool $all = false): string|array|null`, `getHeaders(): array`, `withBody(string $body): self`, `withHeader(string $name, string $value): self`, `withoutHeader(string $name): self`.

### How getContents() Caching Works

Responses are cached for up to **24 hours** (86400 seconds) of *storage horizon*, but freshness is computed per RFC 9111 and is almost never that long:

- **Storage horizon** (86400 s) caps how long the response body is kept in the cache backend, regardless of freshness directives.
- **Freshness** (how long a cached response can be served without revalidation) is computed in this order:
  1. `s-maxage` if present (overrides `max-age` — this is how CDNs override origin caches, and how you should trust a CDN-tuned value).
  2. `max-age`.
  3. `Expires` header.
  4. Heuristic: 10% of `(Date − Last-Modified)`, clamped to **[60, 86400]** seconds.
  5. A floor of **60 seconds** when no validator is present, so an upstream without cache headers still benefits from brief coalescing.
- Successful responses are served with `public, max-age=<full TTL>, stale-while-revalidate=max(120, TTL/4)` and an RFC-9111 `Age` header (current-age model, verified against FreshRSS 1.30.4).
- `no-store` and `private` both forbid storage; `private` additionally **deletes any already-stored entry** for that key (so flipping the directive back does not revive stale data). `no-cache` is stored but revalidated on every use.
- **`must-revalidate` while fresh is RFC-compliant** — a fresh response may be reused without revalidation. The fork's deliberate divergence is **stale-first serving** (see below): on a transient upstream failure a stale body is served even though `must-revalidate` forbids stale reuse (RFC 9111 §4.2.4). This keeps feeds responsive against sites that set `must-revalidate` on slow-changing content.
- Cached responses are revalidated with `ETag` (`If-None-Match`) and `Last-Modified` (`If-Modified-Since`).
- The cache key is a hash of **(url, POST body, headers, identity-curl-options)** — specifically `UA`, `Cookie`, `Referer`, `CUSTOMREQUEST`, `POST`, `HTTPHEADER`, and `PROXY`. Calling `getContents($url)` with and without a `User-Agent` header produces two different cache entries.
- Non-2xx responses (except redirects and 304) throw `HttpException::fromResponse()` — Cloudflare challenge pages are automatically detected and thrown as `CloudFlareException`.
- A 304 without any stored response is retried once without validators; a second 304 raises `HttpException` with status 502.
- Global settings apply via environment variables: `HTTP_TIMEOUT` (60 s default), `HTTP_RETRIES` (2 default; `0` disables retries), `HTTP_MAX_FILESIZE` (40 MB default — **set by `.env.default`, not hardcoded in `HttpClient`**, which defaults to `null`).
- **SSRF protection is on by default** (`HTTP_SSRF_CHECK=true`): requests to internal/reserved IP ranges are blocked, with DNS pinning against rebinding.

For sitemaps there is a dedicated helper returning a list of `{loc, lastmod, changefreq, priority}` entries (values are nullable), and additionally a `news` key for sitemap-news items:

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
    $cachedHtml = $articleDom->saveHtml();
    $this->saveCacheValue($cacheKey, $cachedHtml, 86400);
}

$dom = parseHtmlDocument($cachedHtml);
```

If you only need a fragment (e.g. a `<div>` from a larger page), use the sibling `parseHtmlFragment()` instead — it tolerates unbalanced wrappers better than `parseHtmlDocument()`.

### Parsing XML and Atom Feeds

Use `\Dom\XMLDocument` for XML. Restore the previous libxml state in `finally` and catch parse failures (the new DOM API throws on fatal errors):

```php
$feedXml = (string) getContents(self::URI . 'atom.xml');
if ($feedXml === '') {
    throwServerException('Failed to fetch Atom feed');
}

$previous = libxml_use_internal_errors(true);
try {
    $xml = \Dom\XMLDocument::createFromString($feedXml, LIBXML_NOERROR | LIBXML_NOWARNING);
} catch (\Throwable $e) {
    throwServerException('Failed to parse Atom feed: ' . $e->getMessage());
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
}

foreach ($xml->querySelectorAll('entry') as $entry) {
    // ...
}
```

For HTML, use the `parseHtmlDocument()` helper instead — it handles malformed markup, control characters and invalid UTF-8, and restores the libxml state. Note: `\Dom\HTMLDocument::createFromString()` accepts `LIBXML_NOERROR` but **rejects** `LIBXML_NOWARNING`.

If your goal is to *expand* an existing feed, prefer `FeedExpander` instead of manual XML parsing.

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

> **ℹ️ PHPStan note:** With the locked PHPStan version, `foreach` over `querySelectorAll()` yields items already typed `\Dom\Element` — call Element methods directly. An extra `instanceof \Dom\Element` guard inside such a loop is **redundant**: it does not fail Level 8 (the project sets `treatPhpDocTypesAsCertain: false`), but house style omits it. Narrow only when using `->item($i)` (returns `?\Dom\Node`) or when nodes pass through `mixed` containers:
>
> ```php
> foreach ($node->querySelectorAll('p, div') as $element) {
>     $style = $element->getAttribute('style') ?? '';
> }
>
> $first = $node->querySelectorAll('p')->item(0);
> if ($first instanceof \Dom\Element === true) {
>     $style = $first->getAttribute('style') ?? '';
> }
> ```
>
> `querySelector()` (singular) already returns `?\Dom\Element` and needs only a null check (or `?->`).

`\Dom\NodeList` also supports `->length` and `->item(0)` when you prefer index access.

### Accessing Data

| Property / Method | Available on | Type | Description |
|---|---|---|---|
| `$node->textContent` | `\Dom\Node` | `?string` | Plain text, HTML stripped (null for some node types) |
| `$node->innerHTML` | `\Dom\Element` | `string` | Inner HTML |
| `$node->outerHTML` | `\Dom\Element` | `string` | Element with its inner HTML (preserves attribute order) |
| `$node->C14N()` | `\Dom\Node` | `string\|false` | Canonical serialization of the node incl. its own tag (may reorder attributes; guard the `false` return) |
| `$node->getAttribute('href')` | `\Dom\Element` | `?string` | Attribute value |
| `$node->tagName` | `\Dom\Element` | `string` | Tag name (**UPPERCASE** in HTML documents; original case in XML) |
| `$node->localName` | `\Dom\Element` | `string` | Tag name (always **lowercase**, preferred for comparisons) |
| `$node->parentElement` | `\Dom\Node` | `?\Dom\Element` | Parent element |
| `$node->childNodes` | `\Dom\Node` | `\Dom\NodeList` | Children |

To serialize a single element use `$node->outerHTML` (preserves attribute order) or `$node->C14N()` (canonical form, may reorder attributes; guard the `false` return). For the whole document use `$dom->saveHtml()` (returns `string`).

Use the null-safe operator `?->` for optional elements:

```php
$author = $post->querySelector('.author-name')?->textContent;
$date = $post->querySelector('time')?->getAttribute('datetime');
```

### Traversing the Tree

> **⚠️ PHPStan trap:** In PHP 8.4+ `\Dom\HTMLDocument`, the `tagName` property returns the tag name in **UPPERCASE** (e.g., `'DIV'`, `'A'`) for HTML documents, following the WHATWG DOM spec. Comparing it to a lowercase string (e.g., `$node->tagName === 'div'`) will **always fail**. Always use `$node->localName` for case-insensitive comparisons. The `DomTagNameComparisonRule` enforces this automatically.

```php
$current = $node;
while ($current !== null) {
    // Use localName for reliable lowercase comparison
    if ($current->localName === 'article') {
        break;
    }
    $current = $current->parentElement;
}
```

## HTML Cleanup with Tidy

The native parser is forgiving, and `parseHtmlDocument()` additionally strips control characters and repairs invalid UTF-8. Reach for `tidy` only when a site is so malformed that the native parser still fails:

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

$dom = parseHtmlDocument((string) $tidy);
```

There is no `tidy_html()` helper in this fork — use the `\tidy` class directly as shown above.

**When to use tidy:**

- Site returns HTML with parsing errors
- `parseHtmlDocument()` still fails on a severely broken page
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

// Neutralize dangerous tags (script, object, embed, applet, svg, math, link,
// style, meta, base), strip on* handlers and javascript: URLs.
// Pass true as the 2nd argument to keep <iframe> embeds from trusted video
// hosts (see quirks/video_embed.php). Default false escapes all iframes.
$clean = break_annoying_html_tags($html);
$preview = break_annoying_html_tags($html, true);

// DOM-level sanitization: strip all attributes except a strict whitelist,
// validate href/src schemes, sanitize inline CSS
sanitize_dom_element_attributes($element, ['a' => ['href', 'title']]);

// URI scheme validation (http, https, mailto, ftp; anchors/relative ok;
// data: only for base64 raster images: png, jpeg, gif, webp, avif — SVG rejected)
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
// $options: list of ['text' => string, 'votes' => int, 'percent' => float (optional)].
$pollHtml = render_ascii_poll($pollOptions, $totalVoters, 'ascii');
```

`html_tag()` only allows these attributes: `type`, `id`, `name`, `value`, `placeholder`, `title`, `pattern`, `class`, `for`, `oncontextmenu`, `data-for`, `formtarget`, `tabindex`, `role`, `aria-label` (string), and `checked`, `required`, `selected` (boolean). Anything else throws. Note that `oncontextmenu` is intentionally allowed, so never pass untrusted user input into it.

### String Extraction (`quirks/extract.php`)

Extract data from HTML, inline JavaScript, or CSS styles:

```php
// Extract text between delimiters. Returns string|false:
//   false — start delimiter not found; '' — start found but end missing/empty.
// Guard the false before passing to string functions (strict_types).
$data = extractFromDelimiters($html, 'window.data = ', ';');
if ($data === false) {
    $data = '';
}

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
// Uses a robust regex that handles balanced braces, safely ignoring "};" inside JSON strings.
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
// Safely traverse nested arrays/objects; returns null if any step is missing.
// Path segments are strings — use '0', not 0, for numeric indexes.
$title = read_json_path($data, 'response', 'items', '0', 'title');
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
    'content' => $metadata['content'] ?? $articleContent,
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

// Legacy YouTube helper. Delegates to video_embed_youtube() in quirks/video_embed.php.
// Iframe vs clickable thumbnail is controlled by [youtube] iframe/nocookie in
// /config/.bridges (or .bridges.default). The shipped .bridges.default sets
// iframe = true and nocookie = true, so iframe embeds are the default; set
// iframe = false to fall back to a clickable thumbnail.
// Returns '' if no valid video ID is found.
$embed = handleYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

// Render a Draft.js JSON content block into safe HTML.
// Decodes React-based editor payloads (used by Boosty, Medium, etc.) where styling
// is applied via offset ranges in UTF-16LE code units. Correctly handles surrogate
// pairs (emojis) without splitting them in half. Returns escaped plain text on parse failure.
$richText = render_draftjs_to_html($draftJsonString);
```

### Video Embeds (`quirks/video_embed.php`)

Single source of truth for video players: the trusted-host registry, watch-URL → player-URL
conversion, iframe markup and the legacy YouTube renderer. The HTML sanitizer only asks
`is_allowed_video_embed()` and knows nothing about individual providers.

| Function | Returns | Purpose |
|---|---|---|
| `video_embed_iframe(string $url, array $options = [])` | `string` | Ready-to-use `<iframe>`, or `''` if the URL is not supported |
| `video_embed_url(string $url, array $options = [])` | `?string` | Player URL, or `null` if not convertible |
| `video_embed_provider(string $url)` | `?string` | Provider key (`youtube`, `vk`, …) or `null` |
| `video_embed_host(string $url)` | `string` | Lowercased host of an http(s) URL, `''` otherwise |
| `video_embed_hosts()` | `list<string>` | Flat list of all trusted hosts |
| `is_allowed_video_embed(string $attributes)` | `bool` | Checks an iframe attribute string (used by the sanitizer) |
| `video_embed_youtube_id(string $text)` | `?string` | Extracts an 11-char YouTube ID from a URL or bare ID |
| `video_embed_youtube(string $text, bool $useIframe = false, bool $useNocookie = false)` | `string` | Legacy renderer: iframe or clickable thumbnail |

```php
$player = video_embed_iframe('https://vk.com/video-124242569_456239659');
$player = video_embed_iframe('https://rutube.ru/video/abc123/', ['width' => 854, 'height' => 480]);
$player = video_embed_iframe('https://www.youtube.com/watch?v=dQw4w9WgXcQ', ['nocookie' => true]);

if ($player !== '') {
    $item['content'] = $text . $player;
}
```

**Supported providers and URL forms:**

| Provider | Hosts | Accepted input |
|---|---|---|
| `youtube` | `youtube.com`, `youtube-nocookie.com`, `youtu.be` | `watch?v=`, `youtu.be/`, `shorts/`, `live/`, `embed/`, bare 11-char ID |
| `vk` | `vk.com`, `vk.ru`, `vkvideo.ru` | `video-OID_ID`, `?z=video-OID_ID`, ready `video_ext.php` (passed through, `hash` preserved) |
| `rutube` | `rutube.ru` | `/video/<id>`, `/shorts/<id>`, `/play/embed/<id>` |
| `vimeo` | `vimeo.com` | `/123456789`, channel/staff URLs, unlisted hash (`/id/hash` or `?h=`) |
| `dzen` | `dzen.ru` | `/video/watch/<id>`, `/embed/<id>` |
| `ok` | `ok.ru` | `/video/<id>`, `/live/<id>`, `/videoembed/<id>` |
| `coub` | `coub.com` | `/view/<id>`, `/embed/<id>` |
| `twitch` | `twitch.tv` | `/videos/<id>`, `/clip/<slug>`, `clips.twitch.tv/<slug>`, `/<channel>` |

**Options:**

| Key | Default | Applies to | Notes |
|---|---|---|---|
| `width` | `640` | all | Clamped to 1–3840 |
| `height` | `360` | all | Clamped to 1–2160 |
| `title` | `'Video player'` | all | Escaped |
| `lazy` | `true` | all | `loading="lazy"` vs `"eager"` |
| `nocookie` | `false` | YouTube | Uses `youtube-nocookie.com` |
| `parent` | `DOMAIN` env | Twitch | Required by Twitch; without it the URL is not convertible |

> **⚠️ Always check the return value.** `video_embed_iframe()` returns `''` and `video_embed_url()`
> returns `null` for unsupported URLs — never assume a player was produced. Twitch returns `null`
> when neither the `parent` option nor the `DOMAIN` environment variable is set.

**Interaction with the HTML preview.** `break_annoying_html_tags($html, true)` keeps `<iframe>`
only when `is_allowed_video_embed()` accepts it: host must match the registry exactly or by
subdomain (`evil-youtube.com` does **not** match `youtube.com`), the scheme must be http(s) or
protocol-relative, and `srcdoc` is rejected outright (it overrides `src` and would execute
attacker HTML in our origin). Atom/MRSS/JSON never call the sanitizer, so their embeds are
untouched.

**Adding a provider** — three steps, no changes to `quirks/html.php`:

1. Add the hosts to `VIDEO_EMBED_PROVIDERS`.
2. Write a `video_embed_<name>_url()` converter returning `?string`.
3. Add a `match` arm in `video_embed_url()`.

### Text Filtering (`quirks/filter.php`)

Filter feed items based on user-defined keyword rules. Loaded globally via Composer `files` autoload.

```php
// Syntax:
// - Commas act as logical OR: "word1, word2" matches if EITHER is present.
// - Plus signs act as logical AND: "word1+word2" matches only if BOTH are present.
// - "*" is a wildcard: a part with "*" is a whole-string glob ("Семь*" = starts with,
//   "*Земля*" = contains); a part without "*" matches as a substring.
// - A leading "-" marks an exclusion: exclusions are checked first and win over inclusions.
//   A ruleset of only exclusions keeps everything that is not excluded.
// - "\" escapes the next character (\*, \+, \-, \, are literal).
// - Matching is case-insensitive; whitespace runs are collapsed to single spaces.
// - Empty haystack or empty rules never match.
$rules = 'Семь*, -*Земля*';

if ($filter === '' || matches_keyword_rules($itemTitle, $filter) === true) {
    // Keep the item
}
```

Lower-level helpers are also global: matches_keyword_rule() (single AND rule),
matches_keyword_pattern() (single glob/substring pattern), split_keyword_rules()
(escape-aware splitting), keyword_normalize_text().

> ** ⚠️ Note for AI Agents: Always delegate keyword filtering to matches_keyword_rules().
    A single mixed include/exclude string is supported via the - prefix; the legacy
    two-parameter pattern (separate include and exclude strings, negating the second call)
    remains valid. Remember that + is AND — a literal plus must be escaped as \+.

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
    1,                                // retry attempts
    []                                // additional options
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

    if (is_array($data) === false || isset($data['items']) === false || is_array($data['items']) === false) {
        throwServerException('Invalid JSON response');
    }

    foreach ($data['items'] as $entry) {
        if (is_array($entry) === false) {
            continue;
        }

        $item = ['title' => 'Untitled', 'uri' => self::URI, 'content' => ''];

        if (isset($entry['title']) === true && is_string($entry['title']) === true) {
            $item['title'] = $entry['title'];
        }
        if (isset($entry['url']) === true && is_string($entry['url']) === true) {
            $item['uri'] = $entry['url'];
        }
        if (isset($entry['body']) === true && is_string($entry['body']) === true) {
            $item['content'] = $entry['body'];
        }
        if (isset($entry['date']) === true && is_string($entry['date']) === true) {
            $timestamp = strtotime($entry['date']);
            if ($timestamp !== false) {
                $item['timestamp'] = $timestamp;
            }
        }

        $this->items[] = $item;
    }
}
```

- `Json::decode(string $json, bool $assoc = true): mixed` and `Json::encode(mixed $value, bool $pretty = true, bool $asciiSafe = false): string` both throw `\JsonException` on invalid input (`JSON_THROW_ON_ERROR`)
- The `(string)` cast on `getContents()` is house style; PHPStan already infers `string` here via the conditional return type, so the cast is optional
- An equally valid pattern used by bridges: `json_decode((string) getContents($url, $headers), true, 512, JSON_THROW_ON_ERROR)`
- For deeply nested payloads, combine with `read_json_path()` (see quirks)

## APCu In-Memory Cache

For ultra-fast local caching (faster than memcached), use APCu. Data persists across requests within the same container:

```php
public function collectData(): void
{
    $cacheKey = 'api_config_' . (string) $this->getInput('site');

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

**APCu vs the default bridge cache (memcached):**

- **APCu**: In-memory, local to the container, ~1 µs access time, lost on container restart. Use it for hot, rarely-changing data like per-bridge API configuration or pagination cursors.
- **Memcached (`CACHE_TYPE=memcached`)**: By default, `CACHE_MEMCACHED_TYPE=internal` — a unix-socket instance running **inside the same container under supervisor**, so it is fast but **not shared across containers** and **not persistent across restarts** (data lives in RAM, not disk). Use `CACHE_MEMCACHED_TYPE=external` with `CACHE_MEMCACHED_HOST`/`CACHE_MEMCACHED_PORT` to point at a shared, persistent instance (e.g. a dedicated memcached deployment). There is no automatic APCu→Memcached→File fallback chain — pick a backend via `CACHE_TYPE` (`memcached`, `sqlite`, `file`, `array`, `null`).

**Best practice:** Use APCu for frequently accessed, rarely changing data. Use memcached (external) for shared data across multiple instances.

## Advanced Compression with Zstd

For caching large objects (full HTML pages, JSON responses), compress with `zstd` to save memory. The bridge cache is JSON-based and corrupts raw binary, so always store the compressed payload **base64-encoded**:

```php
public function collectData(): void
{
    $cacheKey = 'full_page_' . md5($url);

    $cached = $this->loadCacheValue($cacheKey);
    $html = '';
    if (is_string($cached) === true && $cached !== '') {
        $compressed = base64_decode($cached, true);
        if ($compressed !== false) {
            $uncompressed = zstd_uncompress($compressed);
            $html = is_string($uncompressed) ? $uncompressed : '';
        }
    }
    if ($html === '') {
        $html = (string) getContents($url);
        $compressed = zstd_compress($html, 3);
        if ($compressed !== false) {
            $this->saveCacheValue($cacheKey, base64_encode($compressed), 86400);
        }
    }

    if ($html === '') {
        throwServerException('Failed to load page');
    }

    $dom = parseHtmlDocument($html);
    // ... process
}
```

**Compression levels:** 1 (fastest) to 22 (smallest). Level 3-5 is optimal for most use cases.

**When to use:**

- Caching full HTML pages (>50KB)
- Large JSON API responses
- Binary data (images, archives) — base64-wrapped as shown above

## Error Handling

All exception classes and helper functions live in the **global namespace** (`lib/exceptions.php`) — no imports needed:

| Class | Extends | Default code |
|---|---|---|
| `HttpException` | `\Exception` | — (carries `?Response $response` and `?int $retryAfter`) |
| `CloudFlareException` (final) | `HttpException` | — |
| `ClientException` | `HttpException` | 400 |
| `ServerException` | `HttpException` | 500 |
| `RateLimitException` | `ClientException` | 429 |
| `ServiceUnavailableException` | `ServerException` | 503 (`retryAfter` default 600) |

Use the helper functions inside `collectData()` (all return `never`):

```php
// User provided bad input (4xx)
throwClientException('Invalid user ID');            // optional 2nd arg: status code

// Server-side or upstream issue (5xx)
throwServerException('Failed to fetch data from API');

// Rate limit hit upstream (429, Retry-After hint)
throwRateLimitException('API rate limit exceeded'); // optional 2nd arg: retryAfter seconds (default 3600)

// Temporary upstream outage (503, Retry-After hint; default 600s)
throwServiceUnavailableException('Upstream temporarily unavailable');

// HTTP-level error with a specific code — note: GLOBAL class, not namespaced
throw new \HttpException('Not Found', 404);
```

`getContents()` throws `HttpException::fromResponse()` automatically on non-2xx responses; Cloudflare challenge pages are detected and raised as `CloudFlareException`. The `Retry-After` header is parsed into `HttpException::$retryAfter` **and is used only to size the server-side backoff window (floored at 120 s, capped at 3600 s, stored as `<key>|backoff`)** — it is **never forwarded to the client**.

### What the client actually sees

The HTTP status returned to feed readers is determined by `httpStatusFromException()` in `lib/exceptions.php` and is **not a 1:1 echo of the bridge's exception code**:

| Bridge exception / condition | HTTP status to client |
|---|---|
| `ClientException` (most 4xx, passthrough) | **passthrough** — the original 4xx is returned |
| `RateLimitException`, `CloudFlareException`, `ServiceUnavailableException`, or any unhandled upstream 429 | **502** (per-feed, stale-first applies) |
| 408 / 504 / cURL errno 28 (timeout) | **504** |
| Other 5xx (`ServerException`, `HttpException` ≥ 500) | **502** |
| Bridge instantiation failure (broken stub) | **500** + server backoff 600 s |

Only `MaintenanceMiddleware` may raise a true 503 to the client.

### Stale-first serving

When a bridge call fails temporarily **and a stale cached response exists**, the framework serves the stale body with HTTP 200, `public, max-age≥60, must-revalidate`, and an `x-feed-stale: 1` header **regardless of `ERROR_OUTPUT`**. `ERROR_OUTPUT` (feed/http/none) is only consulted when there is **no** stale data to fall back to.

Constant 4xx responses are **negatively cached** for 60–180 seconds, so a repeatedly failing bridge does not hammer its upstream on every reader poll.

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
    'enclosures' => ['https://example.com/image.jpg', 'data:image/png;base64,...'], // Optional media; data: URIs limited to base64 raster (png/jpeg/gif/webp/avif)
];
```

### The `uid` chain

The `uid` field is used by feed readers to detect duplicates, and its journey through the framework is more opinionated than it looks:

1. If the bridge supplies a `uid`, `FeedItem::setUid()` **sha1-hashes it** unless it is already a 40-character hex string. Expect a one-time duplicate spike in readers the first time an existing bridge switches to this fork (the hashed uid differs from the old literal).
2. In `AtomFormat`, the final entry ID is: `urn:sha1:<uid>` if `uid` was set; otherwise the entry's `uri`; and as a last resort `urn:sha1:<sha1(title . content)>`.
3. The RSS/MRSS formats use similar fallbacks and likewise emit deterministic IDs so that re-imports do not re-create the same item under a different ID.

Custom keys beyond the documented set are allowed — `FeedItem::fromArray()` stores them in a `misc` bag and round-trips them back via `toArray()`. This is how `FeedExpander` bridges pass extra data (e.g. `apod` metadata) between `collectExpandableDatas()` and `parseItem()`. `AtomFormat` additionally supports a **single podcast-style enclosure** under `enclosure` (with `url`, `length`, `type` keys) via the misc bag.

### Enclosures and data: URIs

`FeedItem::setEnclosures()` accepts a flat list of URL strings. `data:` URIs are accepted **only** for base64-encoded raster images (png/jpeg/gif/webp/avif), and only under a hard length limit (`FeedItem::MAX_DATA_URI_LENGTH`) — anything else is silently dropped to avoid feed bloat and scriptable payloads.

## Caching

Two layers of caching coexist; they are often confused:

### Upstream HTTP cache (`getContents()`)

Successful responses are stored automatically by `getContents()` for up to **86400 seconds** of storage horizon, with freshness computed per RFC 9111 (see *How getContents() Caching Works*). The cache key incorporates the URL, POST body, headers, and identity-relevant curl options, so different requests to the same URL with different `User-Agent` / `Cookie` / `Referer` headers do not collide.

### Bridge-level cache (`loadCacheValue` / `saveCacheValue`)

For caching parsed or processed data, use the bridge-level cache helpers:

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

Values passed to `saveCacheValue()` must be JSON-serializable (scalars and arrays; `Response` is specially supported **only as the top-level value** — nested `Response` objects throw `InvalidArgumentException`). Arbitrary objects throw `InvalidArgumentException` — the cache deliberately avoids PHP `unserialize()` for security. Raw binary strings are corrupted by the JSON encoding, so base64-encode them first (see the Zstd example above).

The effective feed `max-age` and the bridge-cache TTL are driven by the bridge's `CACHE_TIMEOUT` constant, which is applied **unconditionally** by `DisplayAction`. A user may override it with `?_cache_timeout=<seconds>` on the feed URL, **but only when the `CACHE_CUSTOM_TIMEOUT=true` environment variable is set**, and values above **86400** (`MAX_CACHE_TIMEOUT` / `MAX_MAX_AGE`) are clamped. The magic value `_cache_timeout=0` bypasses the cache for that single request. `CACHE_TIMEOUT=0` disables caching for the bridge entirely.

> **Note on `.env.default`:** an older comment in `.env.default` used to claim that `CACHE_CUSTOM_TIMEOUT=false` "enforces the global TTL" and ignores the bridge constant. That is outdated — `CACHE_TIMEOUT` is always honored; `CACHE_CUSTOM_TIMEOUT` only gates the URL-override knob.

Alternatively, access the cache backend directly (no prefixing) and the logger:

```php
if ($this->cache !== null) {
    $this->cache->get($cacheKey);
    $this->cache->set($cacheKey, $data, $ttl);
}
if ($this->logger !== null) {
    $this->logger->warning('Upstream returned unexpected payload');
}
```

Both `$this->cache` and `$this->logger` are injected by `BridgeFactory` through the constructor. Do not define your own constructor unless you forward `($cache, $logger)` to `parent::__construct()`.

## Proxy Profiles

For Cloudflare-protected sites or sites requiring specific IPs, use the proxy system:

```php
// Fetch raw HTML via a named proxy profile
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

> **⚠️ What they actually throw:** if the profile is referenced but not configured via environment variables, `getProtectedContents()` throws a **`ServerException`** (→ HTTP 500 to the client), with a message that lists the expected `PROXY_PROFILE_*` variables. On a network/fetch failure they throw **`ServiceUnavailableException`** with `retryAfter` = 600 s for `DirectProxy` and 60 s for other proxies (→ HTTP 502 per the table in *Error Handling*). They do not throw `ClientException` — a proxy failure is never the reader's fault.

### Configuring Proxy Profiles

Proxy profiles are now configured via **environment variables** (processed by `/bin/load-env`). The profile name implicitly defines the proxy type (e.g., profile `flaresolverr` → `FlareSolverrProxy`).

**Available proxy types:**
- `direct` — Standard curl-impersonate requests (always available, no configuration needed)
- `tgws` — SOCKS5 proxy for Telegram Web Services
- `flaresolverr` — Cloudflare bypass service
- `trawl` — Advanced web scraping with CAPTCHA solving

**Example environment variables:**

```bash
# FlareSolverr (Cloudflare bypass)
PROXY_PROFILE_FLARESOLVERR_URL=http://flaresolverr:8191
PROXY_PROFILE_FLARESOLVERR_SESSION_NAME=rss_bridge_session

# Trawl (advanced scraping with CAPTCHA solving)
PROXY_PROFILE_TRAWL_URL=http://trawl:8191
PROXY_PROFILE_TRAWL_TIMEOUT=180000
PROXY_PROFILE_TRAWL_TWOCAPTCHA_API_KEY=your_key_here
PROXY_PROFILE_TRAWL_USE_FORWARD_PROXY=true
PROXY_PROFILE_TRAWL_FORWARD_PROXY_PORT=8192

# TgWS (SOCKS5 for Telegram)
PROXY_PROFILE_TGWS_SOCKS_URL=socks5h://user:pass@192.168.1.1:14444
PROXY_PROFILE_TGWS_CONNECT_TIMEOUT=5
PROXY_PROFILE_TGWS_REQUEST_TIMEOUT=20
PROXY_PROFILE_TGWS_RETRIES=1
PROXY_PROFILE_TGWS_BUDGET_SECONDS=45
PROXY_PROFILE_TGWS_FALLBACK_DIRECT=true
```

The system automatically falls back to `DirectProxy` when using `safeFromProfile()`.

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
    $src = $img->getAttribute('src');
    if ($src !== null && $src !== '' && str_starts_with($src, 'http') === false) {
        $img->setAttribute('src', urljoin($articleUrl, $src));
    }
}
$html = $dom->saveHtml();
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

    protected function parseItem(array $item): array
    {
        // Enrich the item with the full article body from the original site.
        $uri = (string) ($item['uri'] ?? '');
        if ($uri === '') {
            return $item;
        }
        $dom = getSimpleHTMLDOM($uri);
        $item['content'] = $dom->querySelector('article')->innerHTML ?? $item['content'];
        return $item;
    }
}
```

- `collectExpandableDatas(string $url, int $maxItems = -1, array $headers = []): void` fetches and parses the feed (`-1` means up to 999 items). It sends `Accept: application/rss+xml, application/atom+xml, */*`, which produces a **separate upstream-cache entry** from a plain `getContents()` on the same URL.
- `parseItem(array $item): array|false` is called for every item; return `false` or `[]` to drop it. An override that never drops items may narrow the return type to `array` (as above) — PHPStan Level 8 requires the declared return type to match what the method actually returns.
- `getName()`, `getURI()`, and `getIcon()` are automatically taken from the parsed feed.
- Custom keys survive the round-trip through the `misc` bag (see *Feed Item Structure*).

## Namespaced Core Classes

Core classes live in the `RSSBridge` namespace tree (loaded via Composer classmap / PSR-4). Import them explicitly when needed:

```php
use RSSBridge\BridgeAbstract;          // required — every bridge extends it
use RSSBridge\FeedExpander;            // base class for feed-expanding bridges
use RSSBridge\Configuration;           // static access to .bridges (bridge-specific tokens only)
use RSSBridge\Paths;                   // centralized directory paths (cache, libraries)
use RSSBridge\Utils\Json;              // JSON encode/decode (throws \JsonException)
use RSSBridge\Utils\Url;               // URL/MIME/formatting helpers
use RSSBridge\Http\Response;           // HTTP response object (no __toString)
use RSSBridge\Caches\CacheInterface;   // type hints for cache parameters
use RSSBridge\Logger\Logger;           // type hints for logger parameters
```

**Exceptions are the opposite:** `HttpException`, `ClientException`, `ServerException`, `RateLimitException`, `ServiceUnavailableException`, `CloudFlareException` and the `throwClientException()`/`throwServerException()`/`throwRateLimitException()`/`throwServiceUnavailableException()` helpers are all in the **global namespace** — do **not** import them. The same applies to `getContents()`, `getSimpleHTMLDOM()`, `getProtected*()`, `get_sitemap()` and all `quirks/` functions.

`RSSBridge\FeedItem` and `RSSBridge\FeedParser` exist but are framework internals — bridges normally work with plain arrays.

> **⚠️ Custom-base-class trap.** The Docker image runs Composer with `--classmap-authoritative`, so the autoloader trusts the classmap **only**. The built-in abstract bases (`GelbooruBase`, `HoyoBase`, etc.) are present in the classmap; a base class you drop into `/config/bridges-v2/` at runtime is **not** in the classmap and will fail to autoload, making the subclass bridge register as a `BrokenBridgeStub`. If you need shared logic between multiple custom bridges, put it in a separate non-bridge file (no `*Bridge.php` suffix) under `bridges-v2/` and rely on the direct `require` that `BridgeFactory` performs — or keep your bridges self-contained and inherit only from `BridgeAbstract` / `FeedExpander` / the built-in bases.

## Running Quality Checks

From the repository root:

```bash
# Lint your bridge
vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php

# Auto-fix style issues
vendor/bin/phpcbf --standard=phpcs.xml bridges-v2/YourBridge.php

# Static analysis (Level 8 is mandatory; phpstan.neon already sets it)
vendor/bin/phpstan analyse bridges-v2/YourBridge.php
```

Or use the Composer shortcuts: `composer lint`, `composer lint:fix`, `composer stan`.

From inside the Docker container (the Alpine image exposes PHP as `php86`; dev dependencies are installed in the image):

```bash
docker exec rss-bridge php /app/vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php
```

## Testing Your Bridge

1. Place the bridge file in `bridges-v2/` — this is the **only** directory `BridgeFactory` scans (non-recursively), and the file name must match `*Bridge.php`. For Docker, you can instead drop the file into `/config/bridges-v2/`; the entrypoint copies it into `/app/bridges-v2/` at startup.
2. Make sure the bridge is enabled via the `ENABLED_BRIDGES` / `DISABLED_BRIDGES` environment variables (comma-separated short names; `ENABLED_BRIDGES=*` enables all, which is the default). The `.bridges` file holds bridge-specific configuration tokens only — it does not control enablement.
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

Bridges are loaded safely in two stages: `BridgeFactory::loadBridgeInSandbox()` runs each bridge file in an **isolated PHP CLI subprocess** (30 s `timeout`, verdict written to a file — NOT parsed from stdout, because the new `\Dom\*` API and PHP 8.6 deprecations legitimately emit warnings that must not mark an otherwise working bridge as broken; verdicts are memoized in APCu with 86400 s positive / 60 s negative TTL keyed by path+mtime+size). `SafeBridgeLoader` then wraps the in-process instantiation in try/catch and returns a `BrokenBridgeStub` on any `Throwable`, preserving the rest of the UI.

> **⚠️ Note:** This protection only applies to the loading/instantiation phase. Runtime fatal errors inside `collectData()` (e.g., calling undefined functions, out of memory) will still crash the process unless handled by an outer execution sandbox or caught explicitly.

Finally, make sure the file is not marked executable in git — CI rejects executable bridge files:

```bash
git update-index --chmod=-x bridges-v2/YourBridge.php
```

## Performance Optimization Tips

### 1. Use Efficient Serialization
For caching large datasets, use `msgpack_pack()` instead of native `serialize()`. The cache is JSON-based and corrupts raw binary, so base64-encode the result before storing:
```php
$serialized = msgpack_pack($data);
if (is_string($serialized) === true) {
    $this->saveCacheValue($key, base64_encode($serialized), $ttl);
}
```

### 2. Compress Large Objects
Use `zstd_compress()` for HTML pages or JSON responses >50KB (base64-wrap before caching, see the Zstd example above):
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
Use `parseHtmlDocument()` for broken HTML; reach for `tidy` only in severe cases:
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
- [ ] File is valid UTF-8 — no raw CP1251/CP1252 bytes (enforced by `Utf8EncodingRule`)
- [ ] `declare(strict_types=1)` at the top
- [ ] `namespace RSSBridge\Bridges;` declared
- [ ] **Concrete** bridges are `final`; abstract bases are an exception (but see the classmap-authoritative trap under *Namespaced Core Classes*) and extend `BridgeAbstract` (or `FeedExpander`)
- [ ] All five required constants defined with `public const`
- [ ] No `simple_html_dom` calls (`str_get_html()`, `->find()`, `->plaintext`) and no legacy `DOM*` classes (`DOMDocument`, `DOMXPath`, ...) — forbidden by `ForbidOldDomApiRule`
- [ ] No case-sensitive comparisons against `\Dom\Element::$tagName` (use `$element->localName`) — enforced by `DomTagNameComparisonRule`
- [ ] Uses `getSimpleHTMLDOM()`, `\Dom\HTMLDocument`, or `\Dom\XMLDocument` for parsing
- [ ] `getContents()` handled correctly: `string` by default (cast optional), `Response` read via `getBody()` when `$returnFull = true`; be aware of the freshness/storage split described in *How getContents() Caching Works*
- [ ] `foreach` over `querySelectorAll()` used directly (items are `\Dom\Element`); `instanceof` narrowing only for `->item($i)` / `mixed`
- [ ] Null-safe operator `?->` used for optional elements; no `?->prop ?? $x` (use `->prop ?? $x`) and no short ternary `?:`
- [ ] Exception helpers (`throwClientException`, ...) used from the **global namespace** — no imports; remember they map to HTTP statuses per the table in *Error Handling* (429/CloudFlare/503 → 502 to the client)
- [ ] `Json` imported as `use RSSBridge\Utils\Json;` if used
- [ ] Relative URLs handled via `urljoin()` (with `use function urljoin;`)
- [ ] Code passes `phpcs` checks (see `phpcs.xml`)
- [ ] Code passes `phpstan` analysis at project level (see `phpstan.neon`) including all three custom rules
- [ ] Bridge tested with real data in Docker and enabled via `ENABLED_BRIDGES` env var
- [ ] Malformed HTML handled via `parseHtmlDocument()`; `tidy` only for severe cases
- [ ] Large cached objects use `msgpack`/`zstd` compression, base64-wrapped before `saveCacheValue()`; `Response` objects passed to `saveCacheValue()` only as the top-level value
- [ ] API authentication uses `sodium` for signatures
- [ ] APCu used for frequently accessed local data
- [ ] Used built-in `quirks/` helpers (e.g., `truncate_html_preserve_tags`, `extract_spa_json_variable`, `matches_keyword_rules`) instead of writing custom regex or substring logic
- [ ] Video players built via `video_embed_iframe()` / `video_embed_url()`; return value checked for `''` / `null`
- [ ] `number` parameters hold only integers; floats are handled via `text` + manual parsing
- [ ] Custom base classes (if any) are either in the composer classmap or loaded via the direct `require` that `BridgeFactory` performs

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
        $limit = (int) ($this->getInput('limit') ?? 20);

        $url = $category !== '' ? self::URI . 'category/' . rawurlencode($category) . '/' : self::URI;

        $html = (string) getContents($url);
        if ($html === '') {
            throwServerException('Empty response from ' . $url);
        }

        $dom = parseHtmlDocument($html);

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
            // Use strip_invisible_unicode to clean up weird formatting characters from titles
            $title = strip_invisible_unicode(trim($link->textContent ?? ''));
            if ($uri === null || $uri === '') {
                continue;
            }

            $articleUrl = urljoin(self::URI, $uri);

            $cacheKey = 'article_' . md5($articleUrl);
            $articleHtml = $this->loadCacheValue($cacheKey);
            if (is_string($articleHtml) === false || $articleHtml === '') {
                $articleDom = getSimpleHTMLDOM($articleUrl);
                $articleHtml = $articleDom->querySelector('.entry-content')->innerHTML ?? '';
                $articleHtml = break_annoying_html_tags($articleHtml);
                $this->saveCacheValue($cacheKey, $articleHtml, self::ARTICLE_CACHE_TTL);
            }

            $metadata = html_find_seo_metadata($articleHtml);

            $dateStr = $post->querySelector('time')?->getAttribute('datetime');
            $timestamp = $dateStr !== null ? strtotime($dateStr) : false;

            $authorName = $post->querySelector('.author-name')?->textContent;
            $author = $authorName !== null && trim($authorName) !== '' ? trim($authorName) : (string) ($metadata['author'] ?? '');

            $this->items[] = [
                'title' => $title,
                'uri' => $articleUrl,
                // Use truncate_html_preserve_tags for a safe summary if full content is missing
                'content' => $articleHtml !== '' ? $articleHtml : truncate_html_preserve_tags($metadata['content'] ?? '', 300),
                'timestamp' => $timestamp !== false ? $timestamp : (int) ($metadata['timestamp'] ?? time()),
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