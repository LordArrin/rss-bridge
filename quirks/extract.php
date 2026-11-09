<?php

declare(strict_types=1);

/**
 * Extract the first part of a string matching the specified start and end delimiters.
 */
function extractFromDelimiters(string $string, string $start, string $end): string|false
{
    $startPos = strpos($string, $start);
    if ($startPos !== false) {
        $sectionStart = $startPos + strlen($start);
        $section_retrieved = substr($string, $sectionStart);
        $endPos = strpos($section_retrieved, $end);
        if ($endPos === false) {
            return false;
        }
        $section_retrieved = substr($section_retrieved, 0, $endPos);
        return $section_retrieved;
    }
    return false;
}

/**
 * Remove one or more part(s) of a string using start and end delimiters.
 */
function stripWithDelimiters(string $string, string $start, string $end): string
{
    while (strpos($string, $start) !== false) {
        $startPos = strpos($string, $start);

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
        while (strpos($string, $tag_start) !== false) {
            $max_recursion = 100;
            $section_to_remove = null;
            $section_start = strpos($string, $tag_start);

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
 * state in a <script> tag as a global variable (e.g., `var ytInitialData = {...};`).
 * This function extracts and decodes the JSON payload for a given variable name.
 *
 * @param string $html The raw HTML content.
 * @param string $variableName The name of the JavaScript variable (e.g., 'ytInitialData').
 * @return array<mixed>|null The decoded JSON as an associative array, or null on failure.
 */
function extract_spa_json_variable(string $html, string $variableName): ?array
{
    $safeVarName = preg_quote($variableName, '/');
    $pattern = '/(?:var|let|const)\s+' . $safeVarName . '\s*=\s*(\{.+?\});/s';

    if (preg_match($pattern, $html, $matches) === 1) {
        try {
            $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) === true) {
                return $decoded;
            }
        } catch (\JsonException $e) {
            return null;
        }
    }

    return null;
}
