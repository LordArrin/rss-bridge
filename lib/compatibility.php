<?php

declare(strict_types=1);

/**
 * Compatibility layer for simple_html_dom
 *
 * This file provides a bridge between legacy simple_html_dom API
 * and modern voku/simple_html_dom (which uses DOMDocument + Symfony CssSelector).
 *
 * Goal: Make legacy bridges work without modification.
 */

use voku\helper\HtmlDomParser;
use RSSBridge\Configuration;

class CompatibilityHtmlDom
{
    private object $dom;

    public function __construct(object $dom)
    {
        $this->dom = $dom;
    }

    /**
     * Find elements by CSS selector
     */
    public function find(string $selector, $idx = null, bool $lowercase = false)
    {
        try {
            $normalizedSelector = self::normalizeSelector($selector);
            $result = $this->dom->find($normalizedSelector, $idx, $lowercase);

            if ($result === null || $result === false) {
                return $idx !== null ? null : [];
            }

            if (is_array($result)) {
                return array_map(fn($node) => new self($node), $result);
            }

            return new self($result);
        } catch (\Throwable $e) {
            self::logIssue('find() failed', [
                'selector' => $selector,
                'idx' => $idx,
                'error' => $e->getMessage(),
            ]);
            return $idx !== null ? null : [];
        }
    }

    /**
     * Get elements by tag name (used by defaultLinkTo)
     *
     * @param string $name Tag name
     * @param int|null $idx Index of element to return (null = return all)
     * @return self[]|self|null
     */
    public function getElementsByTagName(string $name, $idx = null)
    {
        try {
            $result = $this->dom->getElementsByTagName($name, $idx);

            if ($result === null || $result === false) {
                return $idx !== null ? null : [];
            }

            // voku\helper returns SimpleHtmlDomNodeInterface which is iterable
            if ($result instanceof \Traversable || is_array($result)) {
                $wrapped = [];
                foreach ($result as $node) {
                    $wrapped[] = new self($node);
                }
                return $idx !== null ? ($wrapped[$idx] ?? null) : $wrapped;
            }

            return new self($result);
        } catch (\Throwable $e) {
            self::logIssue('getElementsByTagName() failed', [
                'name' => $name,
                'idx' => $idx,
                'error' => $e->getMessage(),
            ]);
            return $idx !== null ? null : [];
        }
    }

    /**
     * Get attribute value
     *
     * @param string $name Attribute name
     * @return string|null Attribute value or null if not exists
     */
    public function getAttribute(string $name): ?string
    {
        try {
            $value = $this->dom->getAttribute($name);
            return $value ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Set attribute value
     *
     * @param string $name Attribute name
     * @param string $value Attribute value
     */
    public function setAttribute(string $name, string $value): self
    {
        try {
            $this->dom->setAttribute($name, $value);
        } catch (\Throwable $e) {
            // Ignore errors
        }
        return $this;
    }

    /**
     * Check if attribute exists
     *
     * @param string $name Attribute name
     * @return bool
     */
    public function hasAttribute(string $name): bool
    {
        try {
            return $this->dom->hasAttribute($name);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Normalize CSS selector for Symfony CssSelector
     */
    public static function normalizeSelector(string $selector): string
    {
        return preg_replace_callback(
            '/\[([a-zA-Z\-_]+)([~|^$*]?=)([^\]"\']+)\]/S',
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
     * Magic getter - delegates to underlying voku\helper object
     */
    public function __get(string $name)
    {
        try {
            return $this->dom->$name ?? null;
        } catch (\Throwable $e) {
            self::logIssue('Property access failed', [
                'property' => $name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Magic setter
     */
    public function __set(string $name, $value): void
    {
        try {
            $this->dom->$name = $value;
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    /**
     * Magic method call
     */
    public function __call(string $name, array $arguments)
    {
        try {
            if (method_exists($this->dom, $name)) {
                return $this->dom->$name(...$arguments);
            }

            // Handle legacy method aliases
            $methodAliases = [
                'innertext' => 'innerHtml',
                'outertext' => 'html',
                'plaintext' => 'text',
            ];

            $lowercaseName = strtolower($name);
            if (isset($methodAliases[$lowercaseName])) {
                return $this->dom->{$methodAliases[$lowercaseName]}(...$arguments);
            }

            return null;
        } catch (\Throwable $e) {
            self::logIssue('Method call failed', [
                'method' => $name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function __isset(string $name): bool
    {
        try {
            return isset($this->dom->$name);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function __unset(string $name): void
    {
        try {
            unset($this->dom->$name);
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    public function __toString(): string
    {
        try {
            return (string) $this->dom;
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getDomParser(): object
    {
        return $this->dom;
    }

    private static function logIssue(string $type, array $context): void
    {
        if (class_exists('Configuration') && Configuration::getConfig('system', 'debug_mode')) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            $bridgeInfo = 'unknown';

            foreach ($trace as $frame) {
                if (isset($frame['file']) && strpos($frame['file'], 'bridges/') !== false) {
                    $bridgeInfo = basename($frame['file']);
                    break;
                }
            }

            error_log(sprintf(
                '[LEGACY_BRIDGE] %s: %s (bridge: %s)',
                $type,
                json_encode($context, JSON_UNESCAPED_UNICODE),
                $bridgeInfo
            ));
        }
    }
}

class_alias(CompatibilityHtmlDom::class, 'simple_html_dom');
class_alias(CompatibilityHtmlDom::class, 'simple_html_dom_node');

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
        if (empty($str)) {
            return null;
        }

        if (class_exists('Configuration')) {
            $maxFileSize = Configuration::getConfig('system', 'max_file_size');
            if ($maxFileSize && strlen($str) > $maxFileSize) {
                return null;
            }
        }

        try {
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
        } catch (\Throwable $e) {
            return null;
        }
    }
}

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

        try {
            $contents = @file_get_contents(
                $url,
                $use_include_path,
                $context,
                $offset,
                $maxLen
            );

            if ($contents === false || empty($contents) || strlen($contents) > $maxLen) {
                return null;
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
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('dump_html_tree')) {
    function dump_html_tree($node, bool $show_attr = true, int $deep = 0): void
    {
        // No-op
    }
}
