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

        // 1. Remove if not in whitelist
        if (!in_array($attrName, $allowed, true)) {
            $attrsToRemove[] = $attrName;
            continue;
        }

        // 2. Strict URI validation for href and src
        if (in_array($attrName, ['href', 'src'], true)) {
            if (!is_safe_uri($attr->value)) {
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
    if ($uri === '' || str_starts_with($uri, '#') || str_starts_with($uri, '/')) {
        return true; // Anchors and relative paths are safe
    }

    $parsed = parse_url($uri);
    if ($parsed === false) {
        return false; // Malformed URI
    }
    
    $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';

    // Strict whitelist of allowed schemes
    $safeSchemes = ['http', 'https', 'mailto', 'ftp'];
    
    // Allow data: ONLY for images (basic check)
    if ($scheme === 'data') {
        return str_starts_with($uri, 'data:image/');
    }

    return in_array($scheme, $safeSchemes, true);
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
