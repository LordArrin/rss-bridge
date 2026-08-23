<?php

/**
 * Parse a srcset HTML attribute value and return size => URL mappings.
 */
function parseSrcset(string $srcset)
{
    $preg_status = preg_match_all('/[\s]*,?[\s]*([^\s]+)\s+([0-9]+[wxh])/', $srcset, $matches);
    $entries = [];
    if ($preg_status !== false && $preg_status > 0) {
        foreach ($matches[1] as $index => $url) {
            if (array_key_exists($index, $matches[2])) {
                $size = $matches[2][$index];
                $entries[$size] = html_entity_decode($url);
            }
        }
    }
    return $entries;
}

/**
 * Parse a srcset HTML attribute value and return the URL of the largest image.
 */
function parseSrcsetLargestImageUrl(string $srcset)
{
    $largest_image_url = null;
    $largest_image_size = -1;
    $entries = parseSrcset($srcset);
    foreach ($entries as $size => $url) {
        $size_int = intval(substr($size, 0, strlen($size) - 1));
        if ($size_int > $largest_image_size) {
            $largest_image_size = $size_int;
            $largest_image_url = $url;
        }
    }
    return $largest_image_url;
}
