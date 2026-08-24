<?php

declare(strict_types=1);

/**
 * SEO metadata extraction from HTML pages.
 *
 * This file provides a single function that extracts structured metadata
 * from HTML pages for use in RSS feed items. It scans two sources:
 *
 * 1. HTML <meta> tags (Open Graph, Twitter Cards, Dublin Core, etc.)
 * 2. Embedded JSON-LD structured data (schema.org Article, Person, etc.)
 *
 * The extracted metadata is returned as an associative array suitable
 * for merging into a FeedItem.
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so the function is available globally without any require.
 */

/**
 * Extract SEO and social media metadata from an HTML page.
 *
 * Scans the HTML for Open Graph, Twitter Cards, standard meta tags,
 * and JSON-LD structured data. Returns an associative array with
 * keys like 'title', 'author', 'timestamp', 'enclosures', etc.
 *
 * @param string|\simple_html_dom $html Raw HTML string or parsed DOM object.
 * @return array{
 *     uri?: string,
 *     title?: string,
 *     content?: string,
 *     timestamp?: int,
 *     enclosures?: array<int, string>,
 *     author?: string,
 * } Extracted metadata (only present keys are included).
 */
function html_find_seo_metadata(string|\simple_html_dom $html): array
{
    if (is_string($html) === true) {
        $html = getSimpleHTMLDOM($html);
    }

    $item = [];

    // == First source of metadata: Meta tags ==
    // Facebook Open Graph (og:KEY) - https://developers.facebook.com/docs/sharing/webmasters
    // Twitter (twitter:KEY) - https://developer.twitter.com/en/docs/twitter-for-websites/cards/guides/getting-started
    // Standard meta tags - https://www.w3schools.com/tags/tag_meta.asp
    // Standard time tag - https://developer.mozilla.org/en-US/docs/Web/HTML/Element/time

    // Each Entry field mapping defines a list of possible <meta> tags names that contains the expected value
    // There are various source candidates per type of data, listed from most reliable to least reliable
    static $meta_mappings = [
        // <meta property="article:KEY" content="VALUE" />
        // <meta property="og:KEY" content="VALUE" />
        // <meta property="KEY" content="VALUE" />
        // <meta name="twitter:KEY" content="VALUE" />
        // <meta name="KEY" content="VALUE">
        // <link rel="canonical" href="URL" />
        // <time datetime="VALUE">text</time>
        'uri' => [
            'og:url',
            'twitter:url',
            'canonical',
        ],
        'title' => [
            'og:title',
            'twitter:title',
        ],
        'content' => [
            'og:description',
            'twitter:description',
            'description',
        ],
        'timestamp' => [
            'article:published_time',
            'og:article:published_time',
            'releaseDate',
            'releasedate',
            'article:modified_time',
            'og:article:modified_time',
            'lastModified',
            'lastmodified',
            'time',
        ],
        'enclosures' => [
            'og:image:secure_url',
            'og:image:url',
            'og:image',
            'twitter:image',
            'thumbnailImg',
            'thumbnailimg',
        ],
        'author' => [
            'article:author',
            'og:article:author',
            'author',
            'article:author:username',
            'profile:first_name',
            'profile:last_name',
            'article:author:first_name',
            'article:author:last_name',
            'twitter:creator',
        ],
    ];

    $author_first_name = null;
    $author_last_name = null;

    // For each Entry property, look for corresponding HTML tags using a list of candidates
    foreach ($meta_mappings as $property => $field_list) {
        foreach ($field_list as $field) {
            // Look for HTML meta tag
            $element = null;
            if ($field === 'canonical') {
                $element = $html->find('link[rel=canonical]');
            } elseif ($field === 'time') {
                $element = $html->find('time[datetime]');
            } else {
                $element = $html->find("meta[property=$field], meta[name=$field]");
            }

            // Found something? Extract the value and populate Entry field
            if ($element !== null && $element !== [] && count($element) > 0) {
                $element = $element[0];
                $field_value = '';
                if ($field === 'canonical') {
                    $field_value = $element->href;
                } elseif ($field === 'time') {
                    $field_value = $element->datetime;
                } else {
                    $field_value = $element->content;
                }

                if ($field_value !== null && $field_value !== '') {
                    if ($field === 'article:author:first_name' || $field === 'profile:first_name') {
                        $author_first_name = $field_value;
                    } elseif ($field === 'article:author:last_name' || $field === 'profile:last_name') {
                        $author_last_name = $field_value;
                    } else {
                        $item[$property] = $field_value;
                        break; // Stop on first match, e.g. og:url has priority over canonical url.
                    }
                }
            }
        }
    }

    // Populate author from first name and last name if all we have is nothing or Twitter @username
    $authorNotSet = isset($item['author']) === false;
    $authorIsTwitterHandle = (
        isset($item['author']) === true
        && is_string($item['author']) === true
        && str_starts_with($item['author'], '@') === true
    );
    $authorMissing = $authorNotSet || $authorIsTwitterHandle;
    $hasFirstName = is_string($author_first_name) === true;
    $hasLastName = is_string($author_last_name) === true;
    $hasNameParts = $hasFirstName || $hasLastName;

    if ($authorMissing === true && $hasNameParts === true) {
        $author = '';
        if (is_string($author_first_name) === true) {
            $author = $author_first_name;
        }
        if (is_string($author_last_name) === true) {
            $author = $author . ' ' . $author_last_name;
        }
        $item['author'] = trim($author);
    }

    // == Second source of metadata: Embedded JSON ==
    // JSON linked data - https://www.w3.org/TR/2014/REC-json-ld-20140116/
    // JSON linked data is COMPLEX and MAY BE LESS RELIABLE than <meta> tags.
    // Used for fields not found as <meta> tags.

    // ld+json object types that hold article metadata
    static $ldjson_article_types = ['webpage', 'article', 'newsarticle', 'blogposting'];
    static $ldjson_article_mappings = [
        'uri' => ['url', 'mainEntityOfPage'],
        'title' => ['headline'],
        'content' => ['description'],
        'timestamp' => ['dateModified', 'datePublished'],
        'enclosures' => ['image'],
        'author' => [['author', 'name'], ['author', '@id'], 'author'],
    ];

    // ld+json object types that hold author metadata
    $ldjson_author_types = ['person', 'organization'];
    $ldjson_author_mappings = []; // ID => Name
    $ldjson_author_id = null;

    // Utility function for checking if JSON array matches one of the desired ld+json object types
    $ldjson_is_of_type = function (array $json, array $allowed_types): bool {
        if (isset($json['@type']) === true) {
            $json_types = $json['@type'];
            if (is_array($json_types) === false) {
                $json_types = [$json_types];
            }
            foreach ($json_types as $item_type) {
                if (is_string($item_type) === true && in_array(strtolower($item_type), $allowed_types, true) === true) {
                    return true;
                }
            }
        }
        return false;
    };

    // Process ld+json objects embedded in the HTML DOM
    foreach ($html->find('script[type=application/ld+json]') as $html_ldjson_node) {
        $json_raw = json_decode($html_ldjson_node->innertext, true);
        if (is_array($json_raw) === true) {
            // The JSON may contain a single object AND/OR several under '@graph'
            $json_items = [$json_raw];
            if (isset($json_raw['@graph']) === true && is_array($json_raw['@graph']) === true) {
                foreach ($json_raw['@graph'] as $json_raw_sub_item) {
                    $json_items[] = $json_raw_sub_item;
                }
            }

            // Process each JSON item individually
            foreach ($json_items as $json) {
                if (is_array($json) === false) {
                    continue;
                }

                // JSON item that holds an ld+json Article object (or a variant)
                if ($ldjson_is_of_type($json, $ldjson_article_types) === true) {
                    foreach ($ldjson_article_mappings as $property => $field_list) {
                        // Skip fields already found as <meta> tags (except Twitter @username)
                        $fieldAlreadySet = isset($item[$property]) === true;
                        $isAuthorField = $property === 'author';
                        $authorIsHandle = (
                            isset($item['author']) === true
                            && is_string($item['author']) === true
                            && str_starts_with($item['author'], '@') === true
                        );
                        $shouldSkipField = $fieldAlreadySet === true && ($isAuthorField === false || $authorIsHandle === false);

                        if ($shouldSkipField === true) {
                            continue;
                        }

                        foreach ($field_list as $field) {
                            $json_root = $json;
                            // Navigate inside the JSON object to access nested fields
                            if (is_array($field) === true) {
                                $json_navigate_ok = true;
                                while (count($field) > 1) {
                                    $sub_field = array_shift($field);
                                    if (is_array($json_root) === true && array_key_exists($sub_field, $json_root) === true) {
                                        $json_root = $json_root[$sub_field];
                                        if (
                                            is_array($json_root) === true
                                            && array_is_list($json_root) === true
                                            && count($json_root) === 1
                                        ) {
                                            $json_root = $json_root[0];
                                        }
                                    } else {
                                        $json_navigate_ok = false;
                                        break;
                                    }
                                }
                                if ($json_navigate_ok === false) {
                                    continue;
                                }
                                $field = $field[0];
                            }

                            // Check for desired field in JSON and populate $item
                            if (is_array($json_root) === true && isset($json_root[$field]) === true) {
                                $field_value = $json_root[$field];
                                if (is_array($field_value) === true && isset($field_value[0]) === true) {
                                    $field_value = $field_value[0];
                                }
                                if (is_string($field_value) === true && $field_value !== '') {
                                    if ($property === 'author' && $field === '@id') {
                                        $ldjson_author_id = $field_value;
                                    } else {
                                        $item[$property] = $field_value;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                // JSON item that holds an ld+json Author object
                } elseif ($ldjson_is_of_type($json, $ldjson_author_types) === true) {
                    $hasId = isset($json['@id']) === true && is_string($json['@id']) === true;
                    $hasName = isset($json['name']) === true && is_string($json['name']) === true;
                    if ($hasId === true && $hasName === true) {
                        $ldjson_author_mappings[$json['@id']] = $json['name'];
                    }
                }
            }
        }
    }

    // Attempt to resolve ld+json author if still missing or Twitter @username
    $authorStillNotSet = isset($item['author']) === false;
    $authorStillIsHandle = (
        isset($item['author']) === true
        && is_string($item['author']) === true
        && str_starts_with($item['author'], '@') === true
    );
    $authorStillMissing = $authorStillNotSet || $authorStillIsHandle;

    if (
        $authorStillMissing === true
        && $ldjson_author_id !== null
        && isset($ldjson_author_mappings[$ldjson_author_id]) === true
    ) {
        $item['author'] = $ldjson_author_mappings[$ldjson_author_id];
    }

    // Adjust item field types
    if (isset($item['enclosures']) === true && is_string($item['enclosures']) === true) {
        $item['enclosures'] = [$item['enclosures']];
    }
    if (isset($item['timestamp']) === true && is_string($item['timestamp']) === true) {
        $parsed = strtotime($item['timestamp']);
        if ($parsed !== false) {
            $item['timestamp'] = $parsed;
        }
    }

    return $item;
}
