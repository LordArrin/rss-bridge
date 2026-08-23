<?php

/**
 * Extract the first part of a string matching the specified start and end delimiters.
 */
function extractFromDelimiters($string, $start, $end)
{
    if (strpos($string, $start) !== false) {
        $section_retrieved = substr($string, strpos($string, $start) + strlen($start));
        $section_retrieved = substr($section_retrieved, 0, strpos($section_retrieved, $end));
        return $section_retrieved;
    }
    return false;
}

/**
 * Remove one or more part(s) of a string using start and end delimiters.
 */
function stripWithDelimiters($string, $start, $end)
{
    while (strpos($string, $start) !== false) {
        $section_to_remove = substr($string, strpos($string, $start));
        $section_to_remove = substr($section_to_remove, 0, strpos($section_to_remove, $end) + strlen($end));
        $string = str_replace($section_to_remove, '', $string);
    }
    return $string;
}

/**
 * Remove HTML sections containing nested elements of the same tag.
 */
function stripRecursiveHTMLSection($string, $tag_name, $tag_start)
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
                $search_offset = $section_end + $close_tag_length;
                $section_to_remove = substr($string, $section_start, $section_end - $section_start + $close_tag_length);
                $open_tag_count = substr_count($section_to_remove, $open_tag);
                $close_tag_count = substr_count($section_to_remove, $close_tag);
            } while ($open_tag_count > $close_tag_count && $max_recursion > 0);
            $string = str_replace($section_to_remove, '', $string);
        }
    }
    return $string;
}
