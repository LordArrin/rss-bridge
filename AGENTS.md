# AGENTS.md

Guidance for AI coding agents working in this repository.

This is **LordArrin/rss-bridge**, a fork of RSS-Bridge rewritten for PHP 8.5: strict types,
native `\Dom\*` parsing, PSR-4 bridges, sandboxed bridge loading, env-driven configuration.
It is **not** compatible with upstream bridges — read this file before writing code.

Human-facing guide: [`documentation/instructions/writing-bridges-en.md`](documentation/instructions/writing-bridges-en.md).
This file is the short, enforceable subset plus the traps that guide does not repeat.

---

## 1. Non-negotiables

1. Every PHP file starts with `declare(strict_types=1);` (see header order in §3).
2. Bridges live in `bridges-v2/`, namespace `RSSBridge\Bridges`, `final class`, filename `*Bridge.php`.
3. **PHPStan level 8** must pass (`composer stan`). No `@phpstan-ignore` to silence your own mistakes.
4. **PHPCS** must pass (`composer lint`). Config: `phpcs.xml`.
5. Legacy DOM API (`DOMDocument`, `DOMXPath`, `DOMNode`, …) is **forbidden** by a custom rule.
6. `simple_html_dom` (`str_get_html`, `->find()`, `->plaintext`) does not exist here.
7. Never weaken security defaults (SSRF checks, sanitizers, enclosure validation) to make code pass.
8. Do not add executable bit to bridge files: `git update-index --chmod=-x bridges-v2/YourBridge.php`.

---

## 2. Repository map

| Path | Contents |
|---|---|
| `bridges-v2/` | Bridges (PSR-4 `RSSBridge\Bridges`) — the only directory scanned |
| `lib/` | Core: `BridgeAbstract`, `FeedExpander`, `FeedItem`, `FeedParser`, `http.php`, `contents.php`, `exceptions.php` |
| `quirks/` | Global helper functions, loaded via Composer `files` autoload |
| `formats/` | Atom, Mrss, Json, Html, Plaintext, Sfeed |
| `actions/` | `FrontpageAction`, `DisplayAction`, `ListAction`, … |
| `middlewares/` | Security, Exception, Maintenance, BasicAuth, Token, Cache |
| `caches/` | File, SQLite, Memcached, Array, Null + `CacheFactory` |
| `proxies/` | Direct, FlareSolverr, TgWS, Trawl |
| `rules/` | Custom PHPStan rules (`ForbidOldDomApiRule`, `DomTagNameComparisonRule`) |
| `templates/` | HTML templates |
| `bin/` | `load-env`, `cache-clear`, `cache-bridge-metadata` |
| `config/` | nginx / php-fpm / php.ini for the Docker image |

---

## 3. File headers (PHPCS enforces the order)

**Bridge / class file** — no file-level docblock:

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class ExampleBridge extends BridgeAbstract
{
```

**Quirk file** — file-level docblock goes **immediately after `<?php`**, before `declare`:

```php
<?php

/**
 * What this file provides.
 */

declare(strict_types=1);
```

Putting `declare` before a file-level docblock fails PHPCS.

---

## 4. Minimal bridge

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
        $title = trim($dom->querySelector('h1')?->textContent ?? '');

        $this->items[] = [
            'title' => $title !== '' ? $title : 'Untitled',
            'uri' => self::URI,
            'content' => '<p>Hello</p>',
            'timestamp' => time(),
        ];
    }
}
```

---

## 5. Core contracts (get these wrong and level 8 fails)

| API | Contract | Required handling |
|---|---|---|
| `getContents($url, $headers, $opts, $returnFull)` | `string\|Response` | `(string) getContents(...)` or `$returnFull = true` + guard |
| `getSimpleHTMLDOM($url)` | `\Dom\HTMLDocument`, throws on empty body | use directly |
| `parseHtmlDocument(string)` | `\Dom\HTMLDocument`, tolerant | preferred for raw/broken HTML |
| `parseHtmlFragment(string, $id)` | `?\Dom\HTMLDocument` | null-check |
| `$this->getInput($key)` | `mixed` (protected) | `is_string()` / `is_numeric()` guard or cast |
| `$this->loadCacheValue($key)` | `mixed` | narrow before use |
| `$this->cache` / `$this->logger` | **nullable** | null-guard, or use `loadCacheValue()` / `saveCacheValue()` |
| `Json::decode()` / `Json::encode()` | `mixed` / `string`, throws `\JsonException` | `use RSSBridge\Utils\Json;` |
| `urljoin($base, $rel)` | `string` | `use function urljoin;` |
| `extractFromDelimiters()` | `string\|false` | guard `false` before string functions |
| `read_json_path($v, string ...$path)` | `mixed` | path segments are **strings**: `'0'`, not `0` |

**Exceptions are global — never import them:** `HttpException`, `ClientException`,
`ServerException`, `RateLimitException`, `ServiceUnavailableException`, `CloudFlareException`,
and helpers `throwClientException()`, `throwServerException()`, `throwRateLimitException()`,
`throwServiceUnavailableException()`. Same for `getContents()`, `getSimpleHTMLDOM()`,
`getProtected*()`, `get_sitemap()` and all `quirks/` functions.

**Namespaced classes must be imported:** `RSSBridge\BridgeAbstract`, `RSSBridge\FeedExpander`,
`RSSBridge\Utils\Json`, `RSSBridge\Utils\Url`, `RSSBridge\Http\Response`,
`RSSBridge\Caches\CacheInterface`, `RSSBridge\Logger\Logger`.

---

## 6. DOM rules (PHP 8.4+ `\Dom\*`)

| Member | Type | Note |
|---|---|---|
| `$node->textContent` | `?string` | always `?? ''` before `trim()` |
| `$node->innerHTML` | `string` | inner markup |
| `$node->C14N()` | `string\|false` | node incl. its own tag; guard `false` |
| `$doc->saveHtml()` / `$doc->saveHtml($node)` | `string` | preferred over `C14N()` for HTML |
| `$node->getAttribute()` | `?string` | |
| `$node->localName` | `string` | lowercase — **use for comparisons** |
| `$node->tagName` | `string` | UPPERCASE in HTML, original case in XML |
| `$node->prefix` | `?string` | null when unprefixed — `?? 'fallback'` is correct |

- **There is no `outerHTML` property.** Using it silently yields `null`.
- `querySelector()` → `?\Dom\Element`; `?->` is enough.
- **`foreach ($x->querySelectorAll(...) as $el)` already yields `\Dom\Element`.**
  Adding `if ($el instanceof \Dom\Element === false) { continue; }` **fails level 8**
  ("always evaluate to true"). Narrow only for `->item($i)` (`?\Dom\Node`) or `mixed` containers.
- `\Dom\HTMLDocument::createFromString()` accepts `LIBXML_NOERROR` but **rejects** `LIBXML_NOWARNING`.
- For XML use `\Dom\XMLDocument` and restore libxml state in `finally`; fatal parse errors throw.

---

## 7. Frontpage metadata: `getName()` / `getURI()` must be stateless

`BridgeMetadataCache::buildMetadata()` instantiates each bridge and calls `getName()`,
`getURI()`, `getDescription()`, `getParameters()` **without** `setInput()` or `collectData()`.

Therefore:

- `getName()` must return a non-empty string with no input. Prefer the inherited
  implementation (`static::NAME`). If you must vary it, guard on `$this->queriedContext === null`.
- `getURI()` must fall back to `self::URI` when input is absent.
- A custom icon requires overriding `getIcon()` — an `ICON` constant alone is **not** read
  by `BridgeAbstract::getIcon()` (it derives `URI . '/favicon.ico'`).

Violating this produces a bridge card with an empty title.

---

## 8. Feed items

Required: `title`, `uri`, `content`. Optional: `timestamp` (int), `author`, `uid`,
`categories` (list), `enclosures` (list). Unknown keys survive via the `misc` bag.

- `timestamp`: pass an **int** (or a `strtotime()`-parsable string); `0` becomes `null`.
- `uri`: must start with `http://` / `https://` — relative URLs are dropped.
- `enclosures`: absolute http(s) URLs, or `data:` URIs limited to base64 raster
  (`png|jpeg|gif|webp|avif`), max 2 MiB of text. SVG and other `data:` types are rejected.
- `uid`: a 40-char lowercase sha1 is preserved; anything else is hashed with `sha1()`.
- Prefer building HTML as strings. `FeedItem::setContent()` also accepts
  `\Dom\HTMLDocument` / `\Dom\Element`.

---

## 9. Quirks catalogue (global functions, no import)

| File | Highlights |
|---|---|
| `html.php` | `e()`, `raw()`, `truncate()`, `html_tag()`, `html_input()`, `html_option()`, `break_annoying_html_tags($html, $allowVideoEmbeds = false)`, `sanitize_dom_element_attributes()`, `is_safe_uri()`, `sanitize_inline_css()`, `strip_invisible_unicode()`, `truncate_html_preserve_tags()`, `render_ascii_poll()` |
| `video_embed.php` | `video_embed_iframe()`, `video_embed_url()`, `video_embed_provider()`, `video_embed_hosts()`, `is_allowed_video_embed()`, `video_embed_youtube()` |
| `extract.php` | `extractFromDelimiters()`, `stripWithDelimiters()`, `stripRecursiveHTMLSection()`, `extract_css_background_image()`, `extract_spa_json_variable()` |
| `media.php` | `markdownToHtml()`, `handleYoutube()`, `render_draftjs_to_html()` |
| `media_embed.php` | `media_embed_url_to_data_uri()`, `media_embed_fetch_binary()`, `media_embed_parse_size()` |
| `sanitize_image.php` | `sanitize_image()` — re-encodes to WebP, placeholder on failure |
| `seo.php` | `html_find_seo_metadata()` |
| `json_path.php` | `read_json_path()` |
| `filter.php` | `matches_keyword_rules()` — `,` = OR, `+` = AND |
| `srcset.php` | `parseSrcset()`, `parseSrcsetLargestImageUrl()` |
| `unicode.php` | `normalize_unicode()`, `trim_unicode()`, `normalize_and_trim()` |
| `template.php` | `render()`, `render_template()` |

**Reuse these instead of hand-rolled regex.** SPA JSON → `extract_spa_json_variable()`;
keyword filters → `matches_keyword_rules()`; HTML summaries → `truncate_html_preserve_tags()`;
video players → `video_embed_iframe()`.

Adding a new quirk file requires registering it in `composer.json` → `autoload.files`
**and** running `composer dump-autoload -o`.

---

## 10. Video embeds

All player logic lives in `quirks/video_embed.php`. Do not add hosts to `quirks/html.php`.

```php
$player = video_embed_iframe($url, ['width' => 854, 'height' => 480]);
if ($player !== '') {
    $item['content'] = $text . $player;
}
```

`video_embed_iframe()` returns `''`, `video_embed_url()` returns `null` for unsupported URLs.
Twitch needs `parent` (option or `DOMAIN` env). The HTML preview keeps iframes only from
hosts in `VIDEO_EMBED_PROVIDERS`; `srcdoc` is always rejected.

To add a provider: hosts in `VIDEO_EMBED_PROVIDERS` → `video_embed_<name>_url()` converter →
`match` arm in `video_embed_url()`.

---

## 11. Style rules that trip agents up

- Max line length **240** (`Generic.Files.LineLength`). Break long arrays into 2–3 entries per line.
- **Explicit boolean comparisons** (`Squiz.Operators.ComparisonOperatorUsage`):
  `is_string($x) === true`, `isset($a[$k]) === false`, `in_array($a, $b, true) === true`.
  Bare `if (is_string($x))` is an error.
- Ternaries must stay on **one line** (`Squiz.WhiteSpace.OperatorSpacing` rejects newlines
  before `?` / `:`). Use `if`/`else` when it would get long.
- Single-quoted strings unless interpolating; one space around `.`.
- Forbidden: `sizeof`, `join`, `intval`/`floatval`/`strval`, `is_null`, `print`, `utf8_encode`,
  `utf8_decode`, `var_dump`, `print_r`, `exec`, `shell_exec`.
- **No duplicate array keys** — PHPStan reports `array.duplicateKey`. Merge maps deliberately.
- Regex delimiters: if the pattern contains `#`, use `~` (or vice versa). A `#` inside a
  `#`-delimited pattern silently truncates it ("Unknown modifier").
- Never write the literal `@phpstan-ignore` token inside a PHP docblock as prose — PHPStan
  parses it as a real directive and fails on trailing punctuation.

---

## 12. Configuration & environment

- System settings come from **environment variables**, normalized by `bin/load-env`
  (`.env.default` ← `/config/.env` ← OS env).
- Bridge enablement: `ENABLED_BRIDGES` / `DISABLED_BRIDGES` (`*` = all).
  `.bridges` holds **bridge-specific tokens only**, not enablement.
- Secrets: declare `CONFIGURATION` in the bridge, read via `$this->getOption()`.
- Cache backend: `CACHE_TYPE` (`memcached` default, `sqlite`, `file`, `array`, `null`).
  There is no automatic fallback chain.
- `saveCacheValue()` accepts JSON-serializable data (scalars, arrays; `Response` is special).
  Arbitrary objects throw `InvalidArgumentException`.
- Timezone: `TZ` accepts IANA names and whole-hour offsets (`UTC+3` → `Etc/GMT-3`).
  Fractional offsets are unsupported. Feed formats emit UTC via `gmdate()` by design;
  the HTML preview renders in the configured timezone.

---

## 13. Verification (run before declaring success)

```bash
composer lint        # phpcs, whole project
composer lint:fix    # phpcbf auto-fix
composer stan        # phpstan level 8
composer test        # phpunit
```

Single bridge:

```bash
vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php
vendor/bin/phpstan analyse bridges-v2/YourBridge.php
```

Also sanity-check logic against a real page or a saved fixture — static analysis does not
prove the selectors work. Report honestly what you ran and what you could not run.

---

## 14. Deployment gotchas

- `opcache.validate_timestamps = 0` + preload: **editing a file is not enough**, restart the
  container so PHP-FPM recompiles.
- The entrypoint copies `/config/bridges-v2/*.php` over `/app/bridges-v2/` on every start.
  A stale file there silently overrides your edited bridge.
- Bridge metadata is cached for 30 days, keyed by file mtime/size and the enabled-bridge
  filters. Restart or clear the cache after changing names/parameters.
- `docker exec` does **not** inherit the entrypoint-exported env, so `bin/cache-clear` may
  target a different backend than the running app. Prefer a container restart.
- `SafeBridgeLoader` protects **instantiation only**. A fatal inside `collectData()` still
  kills the request — guard external data.

---

## 15. Debugging playbook

| Symptom | First thing to check |
|---|---|
| Bridge card has no title | `getName()` returning state-dependent value (§7); then metadata cache; then a browser ad-blocker hiding the `<h2><a href=…>` link (cosmetic filters hide links to some domains, e.g. `dzen.ru`) |
| Players show as text in Html format | `break_annoying_html_tags()` escaping iframes — pass `true`, and confirm the host is in `VIDEO_EMBED_PROVIDERS` |
| Bridge silently returns 0 items | selectors vs. live markup; `querySelector()` null; `getInput()` returning `null` |
| Wrong dates | source dates without timezone parsed in server TZ; check `TZ` / `date.timezone` |
| Images replaced by a placeholder | `sanitize_image()` memory guard / undecodable input; clear cached data URIs after fixes |
| "Class not found" for a new quirk | missing entry in `composer.json` `autoload.files` or no `dump-autoload` |

---

## 16. Definition of done

- [ ] Header order, namespace, `final class`, five `public const`
- [ ] `composer lint` and `composer stan` clean
- [ ] `getName()` / `getURI()` safe without input
- [ ] All `mixed` values narrowed; nullable cache/logger guarded
- [ ] No legacy DOM API, no `outerHTML`, no redundant `instanceof` in `querySelectorAll` loops
- [ ] Reused `quirks/` helpers instead of custom regex
- [ ] Logic checked against real data or a fixture
- [ ] File not executable in git
- [ ] Docs updated when public behaviour or a helper signature changed