<?php

declare(strict_types=1);

use RSSBridge\Configuration;

/**
 * Media handling utilities for RSS feed items.
 *
 * This file provides helpers for processing media content commonly
 * found in web pages and converting it into feed-friendly HTML:
 *
 * - Markdown to HTML conversion via Parsedown
 * - YouTube video embedding (iframe or clickable thumbnail with WebP/JPEG)
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so the functions are available globally without any require.
 */

/**
 * Convert Markdown text into HTML using the Parsedown library.
 *
 * Supports three Parsedown options that control output behavior:
 * - 'breaksEnabled' (bool): Convert newlines to <br> tags
 * - 'markupEscaped' (bool): Escape HTML markup in the input
 * - 'urlsLinked' (bool): Auto-link URLs in the input text
 *
 * @param string $string The Markdown input text.
 * @param array<string, bool> $config Parsedown configuration options.
 * @return string The rendered HTML output.
 * @throws \InvalidArgumentException If an unknown Parsedown option is provided.
 */
function markdownToHtml(string $string, array $config = []): string
{
    $Parsedown = new Parsedown();

    foreach ($config as $option => $value) {
        if ($option === 'breaksEnabled') {
            $Parsedown->setBreaksEnabled($value);
        } elseif ($option === 'markupEscaped') {
            $Parsedown->setMarkupEscaped($value);
        } elseif ($option === 'urlsLinked') {
            $Parsedown->setUrlsLinked($value);
        } else {
            throw new \InvalidArgumentException("Invalid Parsedown option \"$option\"");
        }
    }

    return $Parsedown->text($string);
}

/**
 * Handle a YouTube video URL by returning an embed or a clickable thumbnail.
 *
 * Thin wrapper around video_embed_youtube(); the player logic lives in
 * quirks/video_embed.php. Iframe mode is opt-in via [youtube] iframe/nocookie
 * in /config/.bridges. Returns '' when no valid video ID is found.
 *
 * @param string $string A string containing a YouTube video URL or video ID.
 * @return string HTML snippet with iframe or thumbnail, or empty string.
 */
function handleYoutube(string $string): string
{
    return video_embed_youtube(
        $string,
        Configuration::getConfig('youtube', 'iframe') === true,
        Configuration::getConfig('youtube', 'nocookie') === true
    );
}

/**
 * Render a Draft.js JSON content block into safe HTML.
 *
 * Draft.js (and similar React-based editors like Boosty) store text as a JSON array
 * where styling is applied via offset ranges in UTF-16LE code units. This function
 * decodes the JSON, converts UTF-16 offsets to UTF-8 characters, and wraps the text
 * in appropriate HTML tags.
 *
 * @param string $jsonContent The raw JSON string representing the Draft.js block content.
 * @return string The rendered HTML. Returns escaped plain text on parse failure.
 */
function render_draftjs_to_html(string $jsonContent): string
{
    if ($jsonContent === '') {
        return '';
    }

    try {
        $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($data) === false || isset($data[0]) === false) {
            return e($jsonContent);
        }

        $textRaw = $data[0];
        if (is_string($textRaw) === true) {
            $text = $textRaw;
        } elseif (is_array($textRaw) === true) {
            $parts = [];
            foreach ($textRaw as $part) {
                if (is_string($part) === true) {
                    $parts[] = $part;
                } elseif (is_scalar($part) === true) {
                    $parts[] = (string) $part;
                }
            }
            $text = implode('', $parts);
        } elseif (is_scalar($textRaw) === true) {
            $text = (string) $textRaw;
        } else {
            return e($jsonContent);
        }

        if ($text === '') {
            return '';
        }

        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $units = str_split($utf16, 2);
        $unitCount = count($units);

        $styles = [];
        if (isset($data[2]) === true && is_array($data[2]) === true) {
            $styles = $data[2];
        }

        $toInt = static function (mixed $value, int $default): int {
            if (is_int($value) === true) {
                return $value;
            }
            if (is_float($value) === true) {
                return (int) $value;
            }
            if (is_string($value) === true && is_numeric($value) === true) {
                return (int) $value;
            }
            return $default;
        };

        $tagMap = [0 => 'strong', 1 => 'em', 2 => 'u', 3 => 's', 4 => 'code'];
        $events = [];
        $depth = 0;

        foreach ($styles as $style) {
            if (is_array($style) === false || count($style) < 3) {
                continue;
            }

            $tag = $tagMap[$toInt($style[0] ?? null, -1)] ?? null;
            if ($tag === null) {
                continue;
            }

            $start = $toInt($style[1] ?? null, 0);
            $end = $start + $toInt($style[2] ?? null, 0);
            if ($start < 0 || $end > $unitCount || $start >= $end) {
                continue;
            }

            $depth++;
            $events[] = ['pos' => $start, 'html' => sprintf('<%s>', $tag), 'type' => 0, 'depth' => $depth];
            $events[] = ['pos' => $end, 'html' => sprintf('</%s>', $tag), 'type' => 1, 'depth' => $depth];
        }

        if ($events !== []) {
            usort($events, function (array $a, array $b): int {
                if ($a['pos'] !== $b['pos']) {
                    return $a['pos'] <=> $b['pos'];
                }
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 1 ? -1 : 1;
                }
                return $a['type'] === 1 ? ($b['depth'] <=> $a['depth']) : ($a['depth'] <=> $b['depth']);
            });
        }

        $tagsByPos = array_fill(0, $unitCount + 1, '');
        foreach ($events as $event) {
            $tagsByPos[$event['pos']] .= $event['html'];
        }

        $output = '';
        for ($i = 0; $i <= $unitCount; $i++) {
            $output .= $tagsByPos[$i];
            if ($i < $unitCount) {
                $hi = unpack('v', $units[$i])[1] ?? 0;
                if ($hi >= 0xD800 && $hi <= 0xDBFF && $i + 1 < $unitCount) {
                    $converted = mb_convert_encoding($units[$i] . $units[$i + 1], 'UTF-8', 'UTF-16LE');
                    $output .= e($converted);
                    $output .= $tagsByPos[++$i] ?? '';
                } else {
                    $converted = mb_convert_encoding($units[$i], 'UTF-8', 'UTF-16LE');
                    $output .= e($converted);
                }
            }
        }

        return str_replace("\n", '<br>', $output);
    } catch (\Throwable $e) {
        return e($jsonContent);
    }
}
