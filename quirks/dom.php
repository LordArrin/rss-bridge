<?php

/**
 * Removes unwanted tags from a given HTML text.
 */
function sanitize(
    $html,
    $tags_to_remove = ['script', 'iframe', 'input', 'form'],
    $attributes_to_keep = ['title', 'href', 'src'],
    $text_to_keep = []
) {
    $htmlContent = str_get_html($html);

    foreach ($htmlContent->find('*') as $element) {
        if (in_array($element->tag, $text_to_keep)) {
            $element->outertext = $element->plaintext;
        } elseif (in_array($element->tag, $tags_to_remove)) {
            $element->outertext = '';
        } else {
            foreach ($element->getAllAttributes() as $attributeName => $attribute) {
                if (!in_array($attributeName, $attributes_to_keep)) {
                    $element->removeAttribute($attributeName);
                }
            }
        }
    }

    return $htmlContent;
}

function break_annoying_html_tags(string $html): string
{
    $html = str_replace('<script', '<&zwnj;script', $html);
    $html = str_replace('<iframe', '<&zwnj;iframe', $html);
    $html = str_replace('<link', '<&zwnj;link', $html);
    return $html;
}

/**
 * Replace background-image CSS with <img /> tags.
 */
function backgroundToImg($htmlContent)
{
    $regex = '/background-image[ ]{0,}:[ ]{0,}url\([\'"]{0,}(.*?)[\'"]{0,}\)/';
    $htmlContent = str_get_html($htmlContent);

    foreach ($htmlContent->find('*') as $element) {
        if (preg_match($regex, $element->style, $matches) > 0) {
            $element->outertext = '<img style="display:block;" src="' . $matches[1] . '" />';
        }
    }

    return $htmlContent;
}

/**
 * Convert relative links in HTML into absolute links.
 */
function defaultLinkTo($dom, string $url)
{
    if ($dom === '' || $dom === null) {
        return $url;
    }

    $string_convert = false;
    if (is_string($dom)) {
        $string_convert = true;
        $dom = str_get_html($dom);
        
        if ($dom === null) {
            return $url;
        }
    }

    foreach ($dom->getElementsByTagName('img', null) as $image) {
        $src = $image->getAttribute('src');
        if ($src !== null && $src !== '') {
            $image->setAttribute('src', urljoin($url, $src));
        }
    }

    foreach ($dom->getElementsByTagName('a', null) as $anchor) {
        $href = $anchor->getAttribute('href');
        if ($href !== null && $href !== '') {
            $anchor->setAttribute('href', urljoin($url, $href));
        }
    }

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

    if ($string_convert) {
        return $dom->outertext ?? '';
    }

    return $dom;
}

/**
 * Convert lazy-loading images and frames into static elements.
 */
function convertLazyLoading($dom)
{
    $string_convert = false;
    if (is_string($dom)) {
        $string_convert = true;
        $dom = str_get_html($dom);
    }

    foreach ($dom->find('img, iframe, source') as $img) {
        if (!empty($img->getAttribute('data-src'))) {
            $img->src = $img->getAttribute('data-src');
        } elseif (!empty($img->getAttribute('data-srcset'))) {
            $img->src = parseSrcsetLargestImageUrl($img->getAttribute('data-srcset'));
        } elseif (!empty($img->getAttribute('data-lazy-src'))) {
            $img->src = $img->getAttribute('data-lazy-src');
        } elseif (!empty($img->getAttribute('data-orig-file'))) {
            $img->src = $img->getAttribute('data-orig-file');
        } elseif (!empty($img->getAttribute('srcset'))) {
            $img->src = parseSrcsetLargestImageUrl($img->getAttribute('srcset'));
        } else {
            continue;
        }

        foreach ($img->getAllAttributes() as $attr => $val) {
            if (str_starts_with($attr, 'data-')) {
                $img->removeAttribute($attr);
            }
        }

        foreach (['loading', 'decoding', 'srcset'] as $attr) {
            if ($img->hasAttribute($attr)) {
                $img->removeAttribute($attr);
            }
        }
    }

    foreach ($dom->find('picture') as $picture) {
        $img = $picture->find('img, source', 0);
        if (!empty($img)) {
            if ($img->tag == 'source') {
                $img->tag = 'img';
            }
            $picture->outertext = $img->outertext;
        }
    }

    $dom = $dom->outertext;
    if (!$string_convert) {
        $dom = str_get_html($dom);
    }

    return $dom;
}
