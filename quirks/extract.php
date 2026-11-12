<?php

declare(strict_types=1);

/**
 * Extract the first part of a string matching the specified start and end delimiters.
 *
 * Return semantics are kept compatible with upstream RSS-Bridge and custom bridges:
 * - false: the start delimiter was not found;
 * - '':    the start delimiter was found but the end delimiter was not (or the section is empty).
 *
 * @return string|false The extracted section, '' for an unterminated section, or false when absent
 */
function extractFromDelimiters(string $string, string $start, string $end): string|false
{
    $startPos = strpos($string, $start);
    if ($startPos === false) {
        return false;
    }

    $sectionStart = $startPos + strlen($start);
    $sectionRetrieved = substr($string, $sectionStart);
    $endPos = strpos($sectionRetrieved, $end);

    if ($endPos === false) {
        return '';
    }

    return substr($sectionRetrieved, 0, $endPos);
}

/**
 * Remove one or more part(s) of a string using start and end delimiters.
 */
function stripWithDelimiters(string $string, string $start, string $end): string
{
    while (($startPos = strpos($string, $start)) !== false) {
        $section_to_remove = substr($string, $startPos);
        $endPos = strpos($section_to_remove, $end);
        if ($endPos === false) {
            break;
        }
        $section_to_remove = substr($section_to_remove, 0, $endPos + strlen($end));
        $string = str_replace($section_to_remove, '', $string);
    }
    return $string;
}

/**
 * Remove HTML sections containing nested elements of the same tag.
 */
function stripRecursiveHTMLSection(string $string, string $tag_name, string $tag_start): string
{
    $open_tag = '<' . $tag_name;
    $close_tag = '</' . $tag_name . '>';
    $close_tag_length = strlen($close_tag);

    if (strpos($tag_start, $open_tag) === 0) {
        while (($section_start = strpos($string, $tag_start)) !== false) {
            $max_recursion = 100;
            $section_to_remove = null;
            $search_offset = $section_start;
            do {
                $max_recursion--;
                $section_end = strpos($string, $close_tag, $search_offset);
                if ($section_end === false) {
                    break;
                }
                $search_offset = $section_end + $close_tag_length;
                $section_length = $section_end - $section_start + $close_tag_length;
                $section_to_remove = substr($string, $section_start, $section_length);
                $open_tag_count = substr_count($section_to_remove, $open_tag);
                $close_tag_count = substr_count($section_to_remove, $close_tag);
            } while ($open_tag_count > $close_tag_count && $max_recursion > 0);

            if ($section_to_remove !== null) {
                $string = str_replace($section_to_remove, '', $string);
            } else {
                break;
            }
        }
    }
    return $string;
}

/**
 * Extract a CSS background image URL from an inline style attribute.
 *
 * Many modern SPAs and CSS-heavy websites use <div style="background-image: url('...')">
 * instead of <img> tags. This function safely extracts the URL from such styles.
 *
 * @param string $style The raw style attribute value.
 * @return string|null The extracted image URL, or null if not found.
 */
function extract_css_background_image(string $style): ?string
{
    if (preg_match('/background(?:-image)?\s*:\s*url\([\'"]?([^\'"()]+)[\'"]?\)/i', $style, $matches) === 1) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Extract a global JSON variable from a SPA HTML page.
 *
 * Modern single-page applications (YouTube, Twitter, etc.) often embed their initial
 * state in a <script> tag as a global variable (e.g. `var ytInitialData = {...};` or
 * `window.ytInitialData = {...};`). This function extracts and decodes the JSON payload.
 *
 * The opening '{' is located with a cheap regex, then the matching closing brace is
 * found with an O(n) scan that ignores braces inside JSON strings. This avoids PCRE
 * recursion/backtrack limits on multi-megabyte payloads and correctly handles '}' or
 * '{' characters appearing inside string values.
 *
 * @param string $html The raw HTML content.
 * @param string $variableName The name of the JavaScript variable (e.g. 'ytInitialData').
 * @return array<mixed>|null The decoded JSON as an associative array, or null on failure.
 */
function extract_spa_json_variable(string $html, string $variableName): ?array
{
    $safeVarName = preg_quote($variableName, '/');

    // Match `var|let|const NAME = {`, `window.NAME = {`, or a bare `NAME = {`.
    // The lookbehind prevents matching NAME as a suffix of a longer identifier.
    $pattern = '/(?:(?:var|let|const)\s+)?(?:window\.)?(?<![\w$])' . $safeVarName . '\s*=\s*\{/s';

    if (preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    // Byte offset of the opening '{'.
    $start = $matches[0][1] + strlen($matches[0][0]) - 1;
    $length = strlen($html);
    $depth = 0;
    $inString = false;
    $escape = false;
    $end = null;

    for ($i = $start; $i < $length; $i++) {
        $char = $html[$i];

        if ($inString === true) {
            if ($escape === true) {
                $escape = false;
            } elseif ($char === '\\') {
                $escape = true;
            } elseif ($char === '"') {
                $inString = false;
            }
            continue;
        }

        if ($char === '"') {
            $inString = true;
        } elseif ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }

    if ($end === null) {
        return null;
    }

    $jsonString = substr($html, $start, $end - $start + 1);

    try {
        $decoded = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded) === true) {
            return $decoded;
        }
    } catch (\JsonException $e) {
        return null;
    }

    return null;
}
