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
 * so the functions are available globally without any require.
 */

/**
 * Check if a haystack string matches a set of keyword rules.
 *
 * The rules string is a comma-separated list of keywords.
 * - Commas act as logical OR: "word1, word2" matches if EITHER is present.
 * - Plus signs act as logical AND: "word1+word2" matches only if BOTH are present.
 * - Matching is case-insensitive.
 *
 * Example rules: "apple, microsoft+windows, linux+ubuntu"
 *
 * @param string $haystack The text to search within (will be lowercased).
 * @param string $rules The comma-separated rules string.
 * @return bool True if at least one rule matches the haystack.
 */
function matches_keyword_rules(string $haystack, string $rules): bool
{
    $haystack = mb_strtolower(trim($haystack), 'UTF-8');
    $rules = trim($rules);

    if ($haystack === '' || $rules === '') {
        return false;
    }

    foreach (explode(',', $rules) as $rule) {
        $rule = trim($rule);
        if ($rule === '') {
            continue;
        }

        if (str_contains($rule, '+') === true) {
            $splitResult = preg_split('/\+/', $rule, -1, PREG_SPLIT_NO_EMPTY);

            if ($splitResult === false) {
                continue;
            }

            $parts = array_map(
                fn(string $p): string => mb_strtolower(trim($p), 'UTF-8'),
                $splitResult
            );

            if ($parts === []) {
                continue;
            }

            $allMatch = true;
            foreach ($parts as $part) {
                if (str_contains($haystack, $part) === false) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch === true) {
                return true;
            }
        } elseif (str_contains($haystack, mb_strtolower($rule, 'UTF-8')) === true) {
            return true;
        }
    }

    return false;
}
