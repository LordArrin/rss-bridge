<?php

declare(strict_types=1);

/**
 * Unicode normalization and text processing utilities.
 *
 * Critical for Asian languages: Korean Hangul (NFD vs NFC),
 * Japanese combining characters, and proper Unicode whitespace trimming.
 */

/**
 * Normalize Unicode text to the specified form.
 *
 * NFC (Normalization Form C) composes characters where possible:
 * - Hangul: ᄀ + ᅡ + ᆨ → 각 (3 code points → 1)
 * - Accented: e + ◌́ → é
 *
 * @param string $text The input text.
 * @param string $form Normalization form: NFC (default), NFD, NFKC, NFKD.
 * @return string Normalized text, or original if intl extension is missing.
 */
function normalize_unicode(string $text, string $form = 'NFC'): string
{
    if ($text === '' || extension_loaded('intl') === false) {
        return $text;
    }

    $normalizerForm = match ($form) {
        'NFD' => \Normalizer::FORM_D,
        'NFKC' => \Normalizer::FORM_KC,
        'NFKD' => \Normalizer::FORM_KD,
        default => \Normalizer::FORM_C,
    };

    $normalized = \Normalizer::normalize($text, $normalizerForm);

    return is_string($normalized) === true ? $normalized : $text;
}

/**
 * Trim Unicode whitespace correctly.
 *
 * PHP's trim() only handles ASCII whitespace. This function removes all
 * Unicode whitespace characters including ideographic space (U+3000),
 * non-breaking space (U+00A0), and zero-width no-break space (U+FEFF).
 *
 * @param string $text The input text.
 * @return string Text with leading/trailing Unicode whitespace removed.
 */
function trim_unicode(string $text): string
{
    if ($text === '') {
        return '';
    }

    // Normalize first to ensure combining chars are attached to base chars
    $text = normalize_unicode($text, 'NFC');

    $pattern = '/^[\p{Z}\s\x{00A0}\x{FEFF}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+|[\p{Z}\s\x{00A0}\x{FEFF}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u';

    $trimmed = preg_replace($pattern, '', $text);

    return is_string($trimmed) === true ? $trimmed : $text;
}

/**
 * Normalize and trim Unicode text in one operation.
 *
 * This is the recommended function for processing text from parsed HTML.
 * Combines normalization (NFC) with proper Unicode whitespace trimming.
 *
 * @param string $text The input text.
 * @return string Normalized and trimmed text.
 */
function normalize_and_trim(string $text): string
{
    return trim_unicode(normalize_unicode($text, 'NFC'));
}
