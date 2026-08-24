<?php

declare(strict_types=1);

/**
 * HTML srcset attribute parsing utilities.
 *
 * The srcset attribute contains a list of image URLs with associated
 * sizes (e.g. "image-640w.jpg 640w, image-1024w.jpg 1024w"). These
 * functions parse srcset values and extract the URLs, typically to
 * find the largest/highest-quality image for RSS feed enclosures.
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so the functions are available globally without any require.
 */

/**
 * Parse an HTML srcset attribute into a size => URL mapping.
 *
 * The srcset format is: "url1 size1, url2 size2, url3 size3"
 * where size is a number followed by 'w' (width), 'x' (scale), or 'h' (height).
 *
 * Example input:
 *   "image-320w.jpg 320w, image-640w.jpg 640w, image-1024w.jpg 1024w"
 *
 * Example output:
 *   ['320w' => 'image-320w.jpg', '640w' => 'image-640w.jpg', '1024w' => 'image-1024w.jpg']
 *
 * @param string $srcset The raw srcset attribute value.
 * @return array<string, string> Associative array of size => URL mappings.
 */
function parseSrcset(string $srcset): array
{
    $preg_status = preg_match_all('/[\s]*,?[\s]*([^\s]+)\s+([0-9]+[wxh])/', $srcset, $matches);

    $entries = [];
    if ($preg_status !== false && $preg_status !== 0) {
        foreach ($matches[1] as $index => $url) {
            if (array_key_exists($index, $matches[2]) === true) {
                $size = $matches[2][$index];
                $entries[$size] = html_entity_decode($url);
            }
        }
    }

    return $entries;
}

/**
 * Parse an HTML srcset attribute and return the URL of the largest image.
 *
 * Extracts all URLs from the srcset, compares their sizes numerically,
 * and returns the URL with the highest size value. This is useful for
 * selecting the best-quality image for RSS feed enclosures.
 *
 * @param string $srcset The raw srcset attribute value.
 * @return string|null The URL of the largest image, or null if no valid entries found.
 */
function parseSrcsetLargestImageUrl(string $srcset): ?string
{
    $largest_image_url = null;
    $largest_image_size = -1;

    $entries = parseSrcset($srcset);

    foreach ($entries as $size => $url) {
        // Extract numeric part from size (e.g. "640w" => 640)
        $size_int = intval(substr($size, 0, strlen($size) - 1));

        if ($size_int > $largest_image_size) {
            $largest_image_size = $size_int;
            $largest_image_url = $url;
        }
    }

    return $largest_image_url;
}
