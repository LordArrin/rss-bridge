<?php

declare(strict_types=1);

/**
 * HTML escaping and tag generation utilities.
 *
 * This file provides a set of pure functions for safely building HTML
 * fragments. All output is escaped by default unless explicitly marked
 * as raw. These helpers are used by templates (via render_template)
 * and by bridges that build HTML content for feed items.
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so every function here is available globally without any require.
 */

/**
 * Escape a string for safe use in an HTML context.
 *
 * Applies htmlspecialchars() with ENT_QUOTES | ENT_SUBSTITUTE and UTF-8
 * encoding. This prevents XSS by converting <, >, &, " and ' into their
 * corresponding HTML entities.
 *
 * Usage in templates:
 *   <?= e($userInput) ?>
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
 * Typically used for pre-rendered HTML fragments that are known
 * to be safe (e.g. output of html_tag()).
 *
 * Usage in templates:
 *   <?= raw($trustedHtml) ?>
 *
 * @param string $s The already-safe HTML string.
 * @return string The same string, unmodified.
 */
function raw(string $s): string
{
    return $s;
}

/**
 * Truncate a string to a maximum length, appending a marker if cut.
 *
 * The input is trimmed first. If the string is already shorter than
 * or equal to $length, it is returned as-is. Otherwise it is cut to
 * $length characters (multibyte-safe) and $marker is appended.
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

/**
 * Generate a self-closing <input> tag.
 *
 * Convenience wrapper around html_tag() for input elements.
 * All attributes are escaped automatically.
 *
 * Example:
 *   html_input(['type' => 'text', 'name' => 'q', 'value' => 'hello'])
 *   => <input type="text" name="q" value="hello" />
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
 * Convenience wrapper around html_tag(). The $selected flag adds
 * the boolean "selected" attribute when true.
 *
 * Example:
 *   html_option('United States', 'us', true)
 *   => <option value="us" selected>United States</option>
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

/**
 * Generate an arbitrary HTML tag with validated attributes.
 *
 * This is the low-level builder behind html_input() and html_option().
 * It enforces a whitelist of allowed attribute names, split into two
 * categories:
 *
 *   - String attributes (type, id, name, value, class, title, etc.):
 *     rendered as key="escaped_value". Skipped when the value is
 *     null, empty string, or false.
 *
 *   - Boolean attributes (checked, required, selected):
 *     rendered as a bare keyword (e.g. "selected") only when the
 *     value is strictly === true.
 *
 * Any attribute name not present in either whitelist triggers an
 * Exception, preventing accidental injection of event handlers
 * (onclick, onerror, etc.) or other dangerous attributes.
 *
 * If $content is provided and non-empty, a paired tag is rendered:
 *   <tag attrs>escaped_content</tag>
 * Otherwise a self-closing tag is rendered:
 *   <tag attrs />
 *
 * @param string              $name       Tag name (e.g. 'div', 'input').
 * @param string|null         $content    Inner text content (will be escaped), or null for self-closing.
 * @param array<string, mixed> $attributes Associative array of allowed attributes.
 * @return string The rendered HTML tag.
 * @throws \Exception If an attribute name is not in the whitelist.
 */
function html_tag(
    string $name,
    ?string $content = null,
    array $attributes = []
): string {
    $html = "<{$name}";

    foreach ($attributes as $key => $value) {
        match ($key) {
            // String attributes: rendered as key="escaped_value"
            'type',
            'id',
            'name',
            'value',
            'placeholder',
            'title',
            'pattern',
            'class',
            'for',
            'oncontextmenu',
            'data-for',
            'formtarget' => (function () use (&$html, $key, $value): void {
                if ($value !== null && $value !== '' && $value !== false) {
                    $html .= sprintf(' %s="%s"', $key, e((string) $value));
                }
            })(),

            // Boolean attributes: rendered as bare keyword when strictly true
            'checked',
            'required',
            'selected' => (function () use (&$html, $key, $value): void {
                if ($value === true) {
                    $html .= sprintf(' %s', $key);
                }
            })(),

            // Unknown attribute: reject to prevent XSS via event handlers
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
