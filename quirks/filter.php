<?php

declare(strict_types=1);

/**
 * Text filtering and search utilities.
 *
 * This file provides helpers for matching text against complex keyword rules,
 * commonly used for filtering RSS feed items based on user-defined inclusion
 * and exclusion criteria.
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so every function here is available globally without any require.
 */

/**
 * Check if a haystack string matches a set of keyword rules.
 *
 * Syntax:
 * - Comma-separated rules act as logical OR.
 * - "+" inside a rule joins parts with logical AND.
 * - "*" is a wildcard (any sequence of characters). A part containing "*"
 *   is matched as a whole-string glob; a part without "*" as a substring.
 * - A rule starting with "-" is an exclusion: if any exclusion matches,
 *   the result is false regardless of inclusions. A ruleset containing
 *   only exclusions matches everything that is not excluded.
 * - "\" escapes the next character: \*, \+, \-, \, and \\ are literal.
 * - Matching is case-insensitive; whitespace runs are collapsed to single
 *   spaces in both the haystack and the rules.
 * - An empty haystack or an empty ruleset never matches.
 *
 * Examples:
 * - "apple, microsoft+windows" — apple OR (microsoft AND windows)
 * - "Семь невест*, -*Земля*" — starts with "Семь невест" and does not contain "Земля"
 * - "Розарио\+Меч*" — literal plus: starts with "Розарио+Меч"
 *
 * @param string $haystack The text to search within.
 * @param string $rules The comma-separated rules string.
 * @return bool True when the haystack satisfies the rules.
 */
function matches_keyword_rules(string $haystack, string $rules): bool
{
    $haystack = keyword_normalize_text($haystack);
    $rules = trim($rules);

    if ($haystack === '' || $rules === '') {
        return false;
    }

    $include = [];
    $exclude = [];

    foreach (split_keyword_rules($rules, ',') as $rule) {
        $rule = trim($rule);
        if ($rule === '') {
            continue;
        }

        if (str_starts_with($rule, '-') === true) {
            $pattern = trim(substr($rule, 1));
            if ($pattern !== '') {
                $exclude[] = $pattern;
            }

            continue;
        }

        $include[] = $rule;
    }

    foreach ($exclude as $pattern) {
        if (matches_keyword_rule($haystack, $pattern) === true) {
            return false;
        }
    }

    if ($include === []) {
        return $exclude !== [];
    }

    foreach ($include as $rule) {
        if (matches_keyword_rule($haystack, $rule) === true) {
            return true;
        }
    }

    return false;
}

/**
 * Match a single rule: "+"-joined parts must all match (logical AND).
 *
 * @param string $haystack The text to search within.
 * @param string $rule A single rule without comma separators.
 * @return bool True when every non-empty part of the rule matches.
 */
function matches_keyword_rule(string $haystack, string $rule): bool
{
    $haystack = keyword_normalize_text($haystack);
    if ($haystack === '') {
        return false;
    }

    $matched = false;

    foreach (split_keyword_rules($rule, '+') as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        if (matches_keyword_pattern($haystack, $part) === false) {
            return false;
        }

        $matched = true;
    }

    return $matched;
}

/**
 * Match a single pattern: a whole-string glob when it contains unescaped "*",
 * a case-insensitive substring otherwise. Backslash escapes are resolved.
 *
 * @param string $haystack The text to search within.
 * @param string $pattern A single pattern without "+" or comma separators.
 * @return bool True when the pattern matches the haystack.
 */
function matches_keyword_pattern(string $haystack, string $pattern): bool
{
    $haystack = keyword_normalize_text($haystack);
    $pattern = keyword_normalize_text($pattern);

    if ($haystack === '' || $pattern === '') {
        return false;
    }

    $runs = [''];
    $hasWildcard = false;
    $length = strlen($pattern);

    for ($i = 0; $i < $length; $i++) {
        $char = $pattern[$i];

        if ($char === '\\' && $i + 1 < $length) {
            $runs[count($runs) - 1] .= $pattern[$i + 1];
            $i++;
            continue;
        }

        if ($char === '*') {
            $hasWildcard = true;
            $runs[] = '';
            continue;
        }

        $runs[count($runs) - 1] .= $char;
    }

    if ($hasWildcard === false) {
        return str_contains($haystack, $runs[0]) === true;
    }

    $quoted = [];
    foreach ($runs as $run) {
        $quoted[] = preg_quote($run, '/');
    }

    return preg_match('/^' . implode('.*', $quoted) . '$/u', $haystack) === 1;
}

/**
 * Split a rules string on an unescaped delimiter, keeping escape pairs intact.
 *
 * @param string $rules The raw rules string.
 * @param string $delimiter Single-byte delimiter character.
 * @return list<string> The split parts (may include empty strings).
 */
function split_keyword_rules(string $rules, string $delimiter): array
{
    $parts = [];
    $current = '';
    $length = strlen($rules);

    for ($i = 0; $i < $length; $i++) {
        $char = $rules[$i];

        if ($char === '\\' && $i + 1 < $length) {
            $current .= $char . $rules[$i + 1];
            $i++;
            continue;
        }

        if ($char === $delimiter) {
            $parts[] = $current;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $parts[] = $current;

    return $parts;
}

/**
 * Lowercase, trim and collapse whitespace runs for case-insensitive matching.
 *
 * @param string $text The input text.
 * @return string The normalized text.
 */
function keyword_normalize_text(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $normalized = preg_replace('/\s+/u', ' ', $text);

    return is_string($normalized) === true ? $normalized : $text;
}
