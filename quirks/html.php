<?php

declare(strict_types=1);

/**
 * HTML escaping, generation, and sanitization utilities.
 *
 * This file provides a set of pure functions for safely building and cleaning HTML.
 * All output is escaped by default unless explicitly marked as raw. These helpers
 * are used by templates (via render_template) and by bridges that build or clean
 * HTML content for feed items.
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so every function here is available globally without any require.
 */

// =============================================================================
// 1. CORE ESCAPING & MARKERS
// =============================================================================

/**
 * Escape a string for safe use in an HTML context.
 *
 * Applies htmlspecialchars() with ENT_QUOTES | ENT_SUBSTITUTE and UTF-8
 * encoding. This prevents XSS by converting <, >, &, " and ' into their
 * corresponding HTML entities.
 *
 * @param string $s The raw string to escape.
 * @return string The HTML-safe string.
 */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Explicitly mark a string as safe (do NOT escape).
 *
 * This is a semantic no-op that serves as a marker in templates:
 * it tells the reader "this value is intentionally not escaped".
 *
 * @param string $s The already-safe HTML string.
 * @return string The same string, unmodified.
 */
function raw(string $s): string
{
    return $s;
}

// =============================================================================
// 2. STRING UTILITIES
// =============================================================================

/**
 * Truncate a string to a maximum length, appending a marker if cut.
 *
 * @param string $s      The input string.
 * @param int    $length Maximum number of characters to keep (default 150).
 * @param string $marker Suffix appended when truncation occurs (default '...').
 * @return string The original or truncated string.
 */
function truncate(string $s, int $length = 150, string $marker = '...'): string
{
    $s = trim($s);
    if (mb_strlen($s) <= $length) {
        return $s;
    }
    return mb_substr($s, 0, $length) . $marker;
}

// =============================================================================
// 3. HTML GENERATION (Building safe HTML)
// =============================================================================

/**
 * Generate an arbitrary HTML tag with validated attributes.
 *
 * Enforces a strict whitelist of allowed attribute names to prevent
 * accidental injection of event handlers (onclick, onerror, etc.).
 *
 * @param string              $name       Tag name (e.g. 'div', 'input').
 * @param string|null         $content    Inner text content (will be escaped), or null for self-closing.
 * @param array<string, mixed> $attributes Associative array of allowed attributes.
 * @return string The rendered HTML tag.
 * @throws \Exception If an attribute name is not in the whitelist.
 */
function html_tag(string $name, ?string $content = null, array $attributes = []): string
{
    $html = "<{$name}";

    foreach ($attributes as $key => $value) {
        match ($key) {
            // String attributes: rendered as key="escaped_value"
            'type', 'id', 'name', 'value', 'placeholder', 'title', 'pattern',
            'class', 'for', 'oncontextmenu', 'data-for', 'formtarget' => (function () use (&$html, $key, $value): void {
                if ($value !== null && $value !== '' && $value !== false) {
                    $html .= sprintf(' %s="%s"', $key, e((string) $value));
                }
            })(),

            // Boolean attributes: rendered as bare keyword when strictly true
            'checked', 'required', 'selected' => (function () use (&$html, $key, $value): void {
                if ($value === true) {
                    $html .= sprintf(' %s', $key);
                }
            })(),

            // Unknown attribute: reject to prevent XSS
            default => throw new \Exception(sprintf('Illegal html tag attribute: %s', $key)),
        };
    }

    if ($content !== null && $content !== '') {
        $html .= sprintf('>%s</%s>', e($content), $name);
    } else {
        $html .= ' />';
    }

    return $html;
}

/**
 * Generate a self-closing <input> tag.
 *
 * @param array<string, mixed> $attributes Associative array of HTML attributes.
 * @return string The rendered <input /> tag.
 */
function html_input(array $attributes): string
{
    return html_tag('input', null, $attributes);
}

/**
 * Generate an <option> tag for use inside a <select> element.
 *
 * @param string $name     The visible label text (will be escaped).
 * @param string $value    The value attribute (will be escaped).
 * @param bool   $selected Whether the option should be pre-selected.
 * @return string The rendered <option> tag.
 */
function html_option(string $name, string $value, bool $selected = false): string
{
    return html_tag('option', $name, [
        'value'    => $value,
        'selected' => $selected,
    ]);
}

// =============================================================================
// 4. STRING-LEVEL HTML SANITIZATION (Fallback for raw HTML strings)
// =============================================================================

/**
 * Break potentially dangerous HTML tags by inserting a zero-width non-joiner.
 *
 * This function sanitizes raw HTML strings to prevent XSS. It performs
 * case-insensitive tag breaking, strips inline event handlers, and
 * neutralizes javascript: URLs.
 *
 * @param string $html The HTML content to sanitize.
 * @return string The sanitized HTML.
 */
function break_annoying_html_tags(string $html): string
{
    $zwnj = "\u{200C}";
    $dangerousTags = 'script|iframe|object|embed|applet|svg|math|link|style|meta|base';

    // 1. Case-insensitive breaking of dangerous tags
    $html = (string) preg_replace('#<(' . $dangerousTags . ')\b#i', '<' . $zwnj . '$1', $html);
    $html = (string) preg_replace('#</(' . $dangerousTags . ')\b#i', '</' . $zwnj . '$1', $html);

    // 2. Neutralize inline event handlers (on*="...")
    $html = (string) preg_replace(
        '#\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)#i',
        ' data-sanitized-handler',
        $html
    );

    // 3. Neutralize javascript: URLs in href and src attributes
    $html = (string) preg_replace(
        '#\b(href|src)\s*=\s*(?:"[^"]*javascript:[^"]*"|\'[^\']*javascript:[^\']*\'|[^\s>"\']*javascript:[^\s>"\']*)#i',
        '$1="about:blank"',
        $html
    );

    return $html;
}

// =============================================================================
// 5. DOM-LEVEL HTML SANITIZATION (The "HTMLPurifier way" without bloat)
// =============================================================================

/**
 * Sanitizes a DOM element by stripping all attributes except a strict whitelist.
 * This is the recommended approach for cleaning parsed HTML in bridges.
 *
 * @param \Dom\Element $element The DOM element to sanitize.
 * @param array<string, array<string>> $allowedAttributes Map of tag names to allowed attributes.
 */
function sanitize_dom_element_attributes(\Dom\Element $element, array $allowedAttributes = []): void
{
    $tagName = strtolower($element->tagName);
    $allowed = $allowedAttributes[$tagName] ?? [];

    // Default safe attributes for common tags if not explicitly defined
    if ($allowed === []) {
        $allowed = match ($tagName) {
            'a' => ['href', 'title', 'rel'],
            'img' => ['src', 'alt', 'title', 'width', 'height'],
            'p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'b', 'i', 'u', 'em', 'strong', 'br', 'ul', 'ol', 'li' => ['class', 'id', 'style'],
            default => [],
        };
    }

    $attrsToRemove = [];
    foreach ($element->attributes as $attr) {
        $attrName = strtolower($attr->name);

        // 1. Remove if not in whitelist (Explicit false comparison)
        if (in_array($attrName, $allowed, true) === false) {
            $attrsToRemove[] = $attrName;
            continue;
        }

        // 2. Strict URI validation for href and src (Explicit true comparison)
        if (in_array($attrName, ['href', 'src'], true) === true) {
            if (is_safe_uri($attr->value) === false) {
                $attrsToRemove[] = $attrName;
            }
        }

        // 3. Sanitize inline CSS if style attribute is allowed
        if ($attrName === 'style') {
            $element->setAttribute('style', sanitize_inline_css($attr->value));
        }
    }

    // Apply removals
    foreach ($attrsToRemove as $attrName) {
        $element->removeAttribute($attrName);
    }
}

/**
 * Validates a URI scheme to prevent javascript:, data: (for non-images), etc.
 *
 * @param string $uri The URI to check.
 * @return bool True if the URI scheme is safe.
 */
function is_safe_uri(string $uri): bool
{
    $uri = trim($uri);

    // Explicit true comparisons for str_starts_with
    if ($uri === '' || str_starts_with($uri, '#') === true || str_starts_with($uri, '/') === true) {
        return true; // Anchors and relative paths are safe
    }

    $parsed = parse_url($uri);
    if ($parsed === false) {
        return false; // Malformed URI
    }

    // Explicit true comparison for array_key_exists
    $scheme = array_key_exists('scheme', $parsed) === true ? strtolower((string) $parsed['scheme']) : '';

    // Strict whitelist of allowed schemes
    $safeSchemes = ['http', 'https', 'mailto', 'ftp'];

    // Allow data: ONLY for images (basic check, explicit true comparison)
    if ($scheme === 'data') {
        return str_starts_with($uri, 'data:image/') === true;
    }

    // Explicit true comparison for in_array
    return in_array($scheme, $safeSchemes, true) === true;
}

/**
 * Sanitizes inline CSS to remove dangerous expressions and imports.
 *
 * @param string $css The inline style attribute value.
 * @return string Sanitized CSS.
 */
function sanitize_inline_css(string $css): string
{
    // Remove IE expression() hacks
    $css = (string) preg_replace('/expression\s*\(/i', 'blocked(', $css);
    // Remove behavior: url(...)
    $css = (string) preg_replace('/behavior\s*:\s*url\s*\(/i', 'blocked:', $css);
    // Remove @import
    $css = (string) preg_replace('/@import/i', '', $css);
    // Remove javascript: inside url()
    $css = (string) preg_replace('/url\s*\(\s*["\']?\s*javascript:/i', 'url("about:blank")', $css);

    return trim($css);
}

/**
 * Strip invisible Unicode characters from a string.
 *
 * Removes zero-width spaces, bidirectional markers, non-breaking spaces, and
 * ASCII control characters that can break text matching, title generation,
 * or cause visual glitches in RSS readers.
 *
 * @param string $text The input text.
 * @return string The cleaned text.
 */
function strip_invisible_unicode(string $text): string
{
    $pattern = '/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u';
    $cleaned = preg_replace($pattern, '', $text);

    return is_string($cleaned) === true ? $cleaned : $text;
}

/**
 * Truncate an HTML string to a maximum number of visible characters without breaking tags.
 *
 * Standard mb_substr() can split HTML tags in half (e.g., leaving an unclosed <a>),
 * which breaks the layout in feed readers. This function tokenizes the HTML, counts
 * only the visible text characters, and safely closes any open tags.
 *
 * @param string $html The input HTML.
 * @param int $limit Maximum number of visible characters to keep.
 * @param string $marker Suffix appended when truncation occurs (default '...').
 * @return string The safely truncated HTML.
 */
function truncate_html_preserve_tags(string $html, int $limit, string $marker = '...'): string
{
    if ($limit <= 0 || trim($html) === '') {
        return '';
    }

    $plainTextLength = mb_strlen(strip_tags($html), 'UTF-8');
    if ($plainTextLength <= $limit) {
        return $html;
    }

    $tokens = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    if ($tokens === false) {
        return $html;
    }

    $consumed = 0;
    $output = '';
    $openTags = [];
    $truncated = false;
    $voidElements = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    foreach ($tokens as $token) {
        if ($truncated === true) {
            break;
        }

        if (str_starts_with($token, '<') === true) {
            if (preg_match('/^<\s*\/\s*([a-zA-Z0-9]+)\b/u', $token, $m) === 1) {
                $tag = strtolower($m[1]);
                $output .= $token;
                for ($i = count($openTags) - 1; $i >= 0; $i--) {
                    if ($openTags[$i] === $tag) {
                        unset($openTags[$i]);
                        $openTags = array_values($openTags);
                        break;
                    }
                }
            } elseif (preg_match('/^<\s*([a-zA-Z0-9]+)\b/u', $token, $m) === 1) {
                $tag = strtolower($m[1]);
                $output .= $token;
                $isSelfClosing = in_array($tag, $voidElements, true) === true || str_ends_with(rtrim($token, '>'), '/');
                if ($isSelfClosing === false) {
                    $openTags[] = $tag;
                }
            } else {
                $output .= $token;
            }
        } else {
            $textLength = mb_strlen($token, 'UTF-8');
            if ($consumed + $textLength <= $limit) {
                $consumed += $textLength;
                $output .= $token;
            } else {
                $remaining = $limit - $consumed;
                $cut = mb_substr($token, 0, $remaining, 'UTF-8');
                $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');
                if ($lastSpace !== false && $lastSpace > ($remaining * 0.5)) {
                    $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
                }
                $output .= rtrim($cut) . $marker;
                $truncated = true;
            }
        }
    }

    while ($openTags !== []) {
        $tag = array_pop($openTags);
        $output .= sprintf('</%s>', $tag);
    }

    return $output;
}

/**
 * Render a poll as an ASCII progress bar list.
 *
 * Generates a text-based representation of poll results using block characters,
 * which is safe and readable in RSS feed readers that do not support complex HTML.
 *
 * @param array<int, array{text: string, votes: int, percent?: float}> $options Poll options.
 * @param int $totalVoters Total number of votes. If 0, percent is calculated from sum of votes.
 * @param string $barStyle Character style for the bar ('ascii' uses [#...], 'blocks' uses Unicode blocks).
 * @return string The rendered HTML snippet containing the poll.
 */
function render_ascii_poll(array $options, int $totalVoters = 0, string $barStyle = 'ascii'): string
{
    if ($options === []) {
        return '';
    }

    if ($totalVoters === 0) {
        foreach ($options as $opt) {
            $totalVoters += (int) ($opt['votes'] ?? 0);
        }
    }

    $html = '<div class="rss-poll" style="font-family: monospace; line-height: 1.5;">';

    foreach ($options as $opt) {
        $text = e((string) ($opt['text'] ?? 'Unknown'));
        $votes = (int) ($opt['votes'] ?? 0);

        $percent = isset($opt['percent']) === true ? (float) $opt['percent'] : 0.0;
        if ($percent === 0.0 && $totalVoters > 0) {
            $percent = ($votes / $totalVoters) * 100.0;
        }

        $pctInt = max(0, min(100, (int) round($percent)));
        $filled = (int) round($pctInt / 5);

        if ($barStyle === 'blocks') {
            $bar = str_repeat('█', (int) round($pctInt / 10)) . str_repeat('░', 10 - (int) round($pctInt / 10));
        } else {
            $bar = '[' . str_repeat('#', $filled) . str_repeat('.', 20 - $filled) . ']';
        }

        $html .= sprintf(
            '<div style="margin: 4px 0;"><strong>%d%%</strong> %s <span style="color:#666; font-size:0.9em;">(%d)</span><br /><code>%s</code></div>',
            $pctInt,
            $text,
            $votes,
            $bar
        );
    }

    if ($totalVoters > 0) {
        $html .= sprintf('<p style="font-size: 0.85em; color: #888; margin-top: 8px;">Total voters: %d</p>', $totalVoters);
    }

    $html .= '</div>';
    return $html;
}
