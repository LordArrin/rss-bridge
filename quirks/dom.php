<?php

declare(strict_types=1);

/**
 * HTML DOM manipulation utilities.
 *
 * This file provides functions for sanitizing, cleaning, and transforming
 * HTML content extracted from web pages. These utilities are essential
 * for preparing HTML content for RSS feed items, ensuring that:
 *
 * - Dangerous tags (scripts, iframes) are removed
 * - Relative URLs are converted to absolute URLs
 * - Lazy-loading images are converted to static images
 * - Background images in CSS are converted to <img> tags
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so the functions are available globally without any require.
 */

/**
 * Remove unwanted HTML tags and attributes from HTML content.
 *
 * This function sanitizes HTML by:
 * - Removing specified tags (default: script, iframe, input, form)
 * - Keeping only specified attributes (default: title, href, src)
 * - Optionally converting specified tags to their text content
 *
 * @param string|\simple_html_dom $html Raw HTML string or parsed DOM object.
 * @param array<int, string> $tags_to_remove List of tag names to remove completely.
 * @param array<int, string> $attributes_to_keep List of attribute names to preserve.
 * @param array<int, string> $text_to_keep List of tag names to convert to their text content.
 * @return \simple_html_dom The sanitized DOM object.
 */
function sanitize(
    string|\simple_html_dom $html,
    array $tags_to_remove = ['script', 'iframe', 'input', 'form'],
    array $attributes_to_keep = ['title', 'href', 'src'],
    array $text_to_keep = []
): \simple_html_dom {
    if (is_string($html) === true) {
        $htmlContent = str_get_html($html);
    } else {
        $htmlContent = $html;
    }

    foreach ($htmlContent->find('*') as $element) {
        if (in_array($element->tag, $text_to_keep, true) === true) {
            $element->outertext = $element->plaintext;
        } elseif (in_array($element->tag, $tags_to_remove, true) === true) {
            $element->outertext = '';
        } else {
            foreach ($element->getAllAttributes() as $attributeName => $attribute) {
                if (in_array($attributeName, $attributes_to_keep, true) === false) {
                    $element->removeAttribute($attributeName);
                }
            }
        }
    }

    return $htmlContent;
}

/**
 * Break annoying HTML tags by inserting zero-width non-joiner characters.
 *
 * This prevents RSS readers from executing scripts, loading iframes,
 * or processing link tags while keeping them visible in the feed.
 *
 * @param string $html The raw HTML string.
 * @return string The HTML with broken tags.
 */
function break_annoying_html_tags(string $html): string
{
    $html = str_replace('<script', '<&zwnj;script', $html);
    $html = str_replace('<iframe', '<&zwnj;iframe', $html);
    $html = str_replace('<link', '<&zwnj;link', $html);
    return $html;
}

/**
 * Replace CSS background-image declarations with <img> tags.
 *
 * Some websites use CSS background-image instead of <img> tags for
 * content images. This function converts them to proper <img> tags
 * so they appear in RSS readers.
 *
 * @param string|\simple_html_dom $htmlContent Raw HTML string or parsed DOM object.
 * @return \simple_html_dom The DOM with background images converted to <img> tags.
 */
function backgroundToImg(string|\simple_html_dom $htmlContent): \simple_html_dom
{
    $regex = '/background-image[ ]{0,}:[ ]{0,}url\([\'"]{0,}(.*?)[\'"]{0,}\)/';

    if (is_string($htmlContent) === true) {
        $htmlContent = str_get_html($htmlContent);
    }

    foreach ($htmlContent->find('*') as $element) {
        $matchResult = preg_match($regex, (string)$element->style, $matches);
        if ($matchResult === 1) {
            $element->outertext = '<img style="display:block;" src="' . $matches[1] . '" />';
        }
    }

    return $htmlContent;
}

/**
 * Convert relative URLs in HTML to absolute URLs.
 *
 * Processes all img, a, script, link, source, video, audio, and iframe
 * tags, converting their src/href attributes from relative to absolute
 * URLs using the provided base URL.
 *
 * @param string|\simple_html_dom $dom Raw HTML string or parsed DOM object.
 * @param string $url The base URL to use for resolving relative URLs.
 * @return string|\simple_html_dom The DOM with absolute URLs (same type as input).
 */
function defaultLinkTo(string|\simple_html_dom $dom, string $url): string|\simple_html_dom
{
    if ($dom === '' || $dom === null) {
        return $url;
    }

    $string_convert = false;
    if (is_string($dom) === true) {
        $string_convert = true;
        $dom = str_get_html($dom);

        if ($dom === null) {
            return $url;
        }
    }

    // Process images
    foreach ($dom->getElementsByTagName('img', null) as $image) {
        $src = $image->getAttribute('src');
        if ($src !== null && $src !== '') {
            $image->setAttribute('src', urljoin($url, $src));
        }
    }

    // Process anchors
    foreach ($dom->getElementsByTagName('a', null) as $anchor) {
        $href = $anchor->getAttribute('href');
        if ($href !== null && $href !== '') {
            $anchor->setAttribute('href', urljoin($url, $href));
        }
    }

    // Process scripts, links, sources, video, audio, iframes
    $tags = ['script', 'link', 'source', 'video', 'audio', 'iframe'];
    foreach ($tags as $tag) {
        foreach ($dom->getElementsByTagName($tag, null) as $element) {
            $attributes = ['src', 'href', 'data-src', 'data-href', 'poster', 'action'];
            foreach ($attributes as $attr) {
                $value = $element->getAttribute($attr);
                if ($value !== null && $value !== '') {
                    $element->setAttribute($attr, urljoin($url, $value));
                }
            }
        }
    }

    if ($string_convert === true) {
        return $dom->outertext ?? '';
    }

    return $dom;
}

/**
 * Convert lazy-loading images and frames into static elements.
 *
 * Many websites use lazy-loading attributes (data-src, data-srcset,
 * data-lazy-src) to defer image loading. This function converts them
 * to standard src attributes so images appear immediately in RSS readers.
 *
 * Also handles <picture> elements by extracting the <img> or <source>.
 *
 * @param string|\simple_html_dom $dom Raw HTML string or parsed DOM object.
 * @return string|\simple_html_dom The DOM with lazy-loaded images converted (same type as input).
 */
function convertLazyLoading(string|\simple_html_dom $dom): string|\simple_html_dom
{
    $string_convert = false;
    if (is_string($dom) === true) {
        $string_convert = true;
        $dom = str_get_html($dom);
    }

    // Process standalone images, embeds and picture sources
    foreach ($dom->find('img, iframe, source') as $img) {
        $dataSrc = $img->getAttribute('data-src');
        $dataSrcset = $img->getAttribute('data-srcset');
        $dataLazySrc = $img->getAttribute('data-lazy-src');
        $dataOrigFile = $img->getAttribute('data-orig-file');
        $srcset = $img->getAttribute('srcset');

        if ($dataSrc !== null && $dataSrc !== '') {
            $img->src = $dataSrc;
        } elseif ($dataSrcset !== null && $dataSrcset !== '') {
            $img->src = parseSrcsetLargestImageUrl($dataSrcset);
        } elseif ($dataLazySrc !== null && $dataLazySrc !== '') {
            $img->src = $dataLazySrc;
        } elseif ($dataOrigFile !== null && $dataOrigFile !== '') {
            $img->src = $dataOrigFile;
        } elseif ($srcset !== null && $srcset !== '') {
            $img->src = parseSrcsetLargestImageUrl($srcset);
        } else {
            continue; // No lazy-loading attributes found
        }

        // Remove data attributes, no longer necessary
        foreach ($img->getAllAttributes() as $attr => $val) {
            if (str_starts_with($attr, 'data-') === true) {
                $img->removeAttribute($attr);
            }
        }

        // Remove other attributes that may be processed by the client
        foreach (['loading', 'decoding', 'srcset'] as $attr) {
            if ($img->hasAttribute($attr) === true) {
                $img->removeAttribute($attr);
            }
        }
    }

    // Convert complex HTML5 pictures to plain, standalone images
    foreach ($dom->find('picture') as $picture) {
        $img = $picture->find('img, source', 0);
        if ($img !== null) {
            if ($img->tag === 'source') {
                $img->tag = 'img';
            }
            $picture->outertext = $img->outertext;
        }
    }

    // If input was string, return string; otherwise return DOM object
    if ($string_convert === true) {
        $dom = $dom->outertext;
    }

    return $dom;
}
