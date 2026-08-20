<?php

declare(strict_types=1);

/**
 * Compatibility layer for simple_html_dom
 * Provides legacy function names and constants that internally use modern voku/simple_html_dom
 * Automatically normalizes CSS selectors for backward compatibility
 */

use voku\helper\HtmlDomParser;

/**
 * Wrapper class that normalizes CSS selectors for backward compatibility
 * Old simple_html_dom allowed unquoted attribute values like [href*=/user/]
 * voku/simple_html_dom (via Symfony CssSelector) requires [href*="/user/"]
 */
class CompatibilityHtmlDom
{
    private object $dom;

    public function __construct(object $dom)
    {
        $this->dom = $dom;
    }

    public function find(string $selector, $idx = null, bool $lowercase = false)
    {
        $selector = self::normalizeSelector($selector);
        $result = $this->dom->find($selector, $idx, $lowercase);

        if ($result === null) {
            return null;
        }

        if (is_array($result)) {
            return array_map(fn($node) => new self($node), $result);
        }

        return new self($result);
    }

    public static function normalizeSelector(string $selector): string
    {
        return preg_replace_callback(
            '/\[([a-zA-Z\-_]+)([~|^$*]?=)([^\]"\']+)\]/',
            function ($matches) {
                $attr = $matches[1];
                $op = $matches[2];
                $value = $matches[3];

                if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                    $value = '"' . $value . '"';
                }

                return '[' . $attr . $op . $value . ']';
            },
            $selector
        );
    }

    /**
     * Magic getter - delegate to underlying object
     * voku\helper\SimpleHtmlDom has its own __get() for innertext, outertext, plaintext
     */
    public function __get(string $name)
    {
        return $this->dom->$name;
    }

    /**
     * Magic setter - delegate to underlying object
     * voku\helper\SimpleHtmlDom has its own __set() for innertext, outertext
     */
    public function __set(string $name, $value)
    {
        $this->dom->$name = $value;
    }

    public function __call(string $name, array $arguments)
    {
        return $this->dom->$name(...$arguments);
    }

    public function __isset(string $name): bool
    {
        return isset($this->dom->$name);
    }

    public function __unset(string $name)
    {
        unset($this->dom->$name);
    }

    public function __toString(): string
    {
        return (string) $this->dom;
    }

    public function getDomParser(): object
    {
        return $this->dom;
    }
}

// Create class aliases for backward compatibility
class_alias(CompatibilityHtmlDom::class, 'simple_html_dom');
class_alias(CompatibilityHtmlDom::class, 'simple_html_dom_node');

// Define legacy constants
if (!defined('HDOM_TYPE_ELEMENT')) {
    define('HDOM_TYPE_ELEMENT', 1);
    define('HDOM_TYPE_COMMENT', 2);
    define('HDOM_TYPE_TEXT', 3);
    define('HDOM_TYPE_ENDTAG', 4);
    define('HDOM_TYPE_ROOT', 5);
    define('HDOM_TYPE_UNKNOWN', 6);
    define('HDOM_QUOTE_DOUBLE', 0);
    define('HDOM_QUOTE_SINGLE', 1);
    define('HDOM_QUOTE_NO', 3);
    define('HDOM_INFO_BEGIN', 0);
    define('HDOM_INFO_END', 1);
    define('HDOM_INFO_QUOTE', 2);
    define('HDOM_INFO_SPACE', 3);
    define('HDOM_INFO_TEXT', 4);
    define('HDOM_INFO_INNER', 5);
    define('HDOM_INFO_OUTER', 6);
    define('HDOM_INFO_ENDSPACE', 7);
}

if (!defined('DEFAULT_TARGET_CHARSET')) {
    define('DEFAULT_TARGET_CHARSET', 'UTF-8');
}

if (!defined('DEFAULT_BR_TEXT')) {
    define('DEFAULT_BR_TEXT', "\r\n");
}

if (!defined('DEFAULT_SPAN_TEXT')) {
    define('DEFAULT_SPAN_TEXT', ' ');
}

if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 600000);
}

if (!defined('HDOM_SMARTY_AS_TEXT')) {
    define('HDOM_SMARTY_AS_TEXT', 1);
}

/**
 * Legacy str_get_html function wrapper
 */
if (!function_exists('str_get_html')) {
    function str_get_html(
        string $str,
        bool $lowercase = true,
        bool $forceTagsClosed = true,
        string $target_charset = DEFAULT_TARGET_CHARSET,
        bool $stripRN = true,
        string $defaultBRText = DEFAULT_BR_TEXT,
        string $defaultSpanText = DEFAULT_SPAN_TEXT
    ) {
        // RSS-Bridge DoS protection patches
        if (empty($str)) {
            throw new \Exception('Refusing to parse empty string input');
        }

        if (class_exists('Configuration')) {
            $maxFileSize = Configuration::getConfig('system', 'max_file_size');
            if ($maxFileSize && strlen($str) > $maxFileSize) {
                throw new \Exception('simple_html_dom: Refusing to parse too big input: ' . strlen($str));
            }
        }

        $dom = HtmlDomParser::str_get_html(
            $str,
            $lowercase,
            $forceTagsClosed,
            $target_charset,
            $stripRN,
            $defaultBRText,
            $defaultSpanText
        );

        return new CompatibilityHtmlDom($dom);
    }
}

/**
 * Legacy file_get_html function wrapper
 */
if (!function_exists('file_get_html')) {
    function file_get_html(
        string $url,
        bool $use_include_path = false,
        $context = null,
        int $offset = 0,
        int $maxLen = -1,
        bool $lowercase = true,
        bool $forceTagsClosed = true,
        string $target_charset = DEFAULT_TARGET_CHARSET,
        bool $stripRN = true,
        string $defaultBRText = DEFAULT_BR_TEXT,
        string $defaultSpanText = DEFAULT_SPAN_TEXT
    ) {
        if ($maxLen <= 0) {
            $maxLen = MAX_FILE_SIZE;
        }

        $contents = file_get_contents(
            $url,
            $use_include_path,
            $context,
            $offset,
            $maxLen
        );

        if (empty($contents) || strlen($contents) > $maxLen) {
            return false;
        }

        return str_get_html(
            $contents,
            $lowercase,
            $forceTagsClosed,
            $target_charset,
            $stripRN,
            $defaultBRText,
            $defaultSpanText
        );
    }
}

/**
 * Legacy dump_html_tree function
 */
if (!function_exists('dump_html_tree')) {
    function dump_html_tree($node, bool $show_attr = true, int $deep = 0): void
    {
        if ($node instanceof CompatibilityHtmlDom) {
            $node = $node->getDomParser();
        }
        $node->dump($node);
    }
}
