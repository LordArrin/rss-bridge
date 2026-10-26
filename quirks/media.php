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
 * Extracts the 11-character YouTube video ID from various URL formats
 * (youtube.com/watch, youtu.be, shorts, embed, etc.) and returns either:
 *
 * - An <iframe> embed (when youtube.iframe is enabled in configuration),
 *   optionally using youtube-nocookie.com for privacy.
 * - A <picture> element with WebP/JPEG srcset thumbnails linking to the
 *   video page (when iframe is disabled), which is safer for RSS readers.
 *
 * Returns an empty string if no valid YouTube video ID is found.
 *
 * @param string $string A string containing a YouTube video URL or video ID.
 * @return string HTML snippet with iframe or thumbnail, or empty string if not found.
 */
function handleYoutube(string $string): string
{
    $useIframe = Configuration::getConfig('youtube', 'iframe');
    $useNocookie = Configuration::getConfig('youtube', 'nocookie');

    $regex = '#(?:https?://|//)?(?:www\.|m\.|.+\.)?(?:youtu\.be/|youtube(?:-nocookie|)\.com/(?:embed/|v/|shorts/|feeds/api/videos/|watch\?v=|watch\?.+&v=))([\w-]{11})#i';
    if (preg_match($regex, $string, $matches) === 1) {
        $videoID = $matches[1];
    } elseif (preg_match('#[\w-]{11}#i', $string, $matches2) === 1) {
        $videoID = $matches2[0];
    } else {
        return '';
    }

    if ($useIframe === true) {
        if ($useNocookie === true) {
            $embedUri = 'https://www.youtube-nocookie.com/embed/' . $videoID;
        } else {
            $embedUri = 'https://www.youtube.com/embed/' . $videoID;
        }

        return sprintf(<<<EOD
<iframe width="560" height="315" src="%s" title="YouTube video player" frameborder="0"
allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
referrerpolicy="strict-origin" allowfullscreen></iframe>'
EOD
         , $embedUri);
    } else {
        $videoUri = 'https://www.youtube.com/watch?v=' . $videoID;

        $thumbnailJpegBaseUri = 'https://i.ytimg.com/vi/' . $videoID;
        $jpegSrcset = sprintf(
            '%1$s/mqdefault.jpg 320w, %1$s/0.jpg 480w, %1$s/hqdefault.jpg 481w, %1$s/sddefault.jpg 640w, %1$s/hq720.jpg 720w, %1$s/maxresdefault.jpg 721w',
            $thumbnailJpegBaseUri
        );

        $thumbnailWebpBaseUri = 'https://i.ytimg.com/vi_webp/' . $videoID;
        $webpSrcset = sprintf(
            '%1$s/mqdefault.webp 320w, %1$s/0.webp 480w, %1$s/hqdefault.webp 481w, %1$s/sddefault.webp 640w, %1$s/hq720.webp 720w, %1$s/maxresdefault.webp 721w',
            $thumbnailWebpBaseUri
        );

        $fallbackUri = $thumbnailJpegBaseUri . '/maxresdefault.jpg';

        return sprintf(<<<EOD
<a href="%s">
    <picture>
        <source srcset="%s" type="image/webp" referrerpolicy="no-referrer" />
        <img srcset="%s" src="%s" alt="Video thumbnail" title="YouTube video thumbnail" referrerpolicy="no-referrer" />
    </picture>
</a>
<p>
<a href="%s">%s</a>
</p>
EOD, $videoUri, $webpSrcset, $jpegSrcset, $fallbackUri, $videoUri, $videoUri);
    }
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

        $text = $data[0];
        if (is_string($text) === false) {
            $text = is_array($text) === true ? implode('', $text) : (string) $text;
        }
        if ($text === '') {
            return '';
        }

        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $units = str_split($utf16, 2);
        $unitCount = count($units);
        $styles = (isset($data[2]) === true && is_array($data[2]) === true) ? $data[2] : [];

        $tagMap = [0 => 'strong', 1 => 'u', 2 => 'em', 3 => 's'];
        $events = [];
        $depth = 0;

        foreach ($styles as $style) {
            if (count($style) < 3) {
                continue;
            }
            $tag = $tagMap[(int) ($style[0] ?? -1)] ?? null;
            if ($tag === null) {
                continue;
            }

            $start = (int) ($style[1] ?? 0);
            $end = $start + (int) ($style[2] ?? 0);
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
                    $output .= e($converted !== false ? $converted : '');
                    $output .= $tagsByPos[++$i] ?? '';
                } else {
                    $converted = mb_convert_encoding($units[$i], 'UTF-8', 'UTF-16LE');
                    $output .= e($converted !== false ? $converted : '');
                }
            }
        }

        return str_replace("\n", '<br>', $output);
    } catch (\Throwable $e) {
        return e($jsonContent);
    }
}
