<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\FeedItem;

/**
 * An alternative abstract class for bridges utilizing XPath expressions.
 *
 * This class is meant as an alternative base class for bridge implementations.
 * It offers preliminary functionality for generating feeds based on XPath
 * expressions.
 *
 * As a minimum, extending classes should define XPath expressions pointing
 * to the feed items contents in the class constants below. In case there is
 * more manual fine tuning required, it offers a bunch of methods which can
 * be overridden, for example in order to specify formatting of field values
 * or more flexible definition of dynamic XPath expressions.
 *
 * This class extends {@see BridgeAbstract}, which means it incorporates and
 * extends all of its functionality.
 */
abstract class XPathAbstract extends BridgeAbstract
{
    /**
     * Source Web page URL (should provide either HTML or XML content).
     * You can specify any website URL which serves data suited for display in RSS feeds
     * (for example a news blog).
     *
     * Use {@see XPathAbstract::getSourceUrl()} to read this parameter.
     */
    public const FEED_SOURCE_URL = '';

    /**
     * XPath expression for extracting the feed title from the source page.
     * If this is left blank or does not provide any data, {@see BridgeAbstract::getName()}
     * is used instead as the feed's title.
     *
     * Use {@see XPathAbstract::getExpressionTitle()} to read this parameter.
     */
    public const XPATH_EXPRESSION_FEED_TITLE = './/title';

    /**
     * XPath expression for extracting the feed favicon URL from the source page.
     * If this is left blank or does not provide any data, {@see BridgeAbstract::getIcon()}
     * is used instead as the feed's favicon URL.
     *
     * Use {@see XPathAbstract::getExpressionIcon()} to read this parameter.
     */
    public const XPATH_EXPRESSION_FEED_ICON = './/link[@rel="icon"]/@href';

    /**
     * XPath expression for extracting the feed items from the source page.
     * Enter an XPath expression matching a list of dom nodes, each node containing one
     * feed article item in total (usually a surrounding <div> or <span> tag). This will
     * be the context nodes for all of the following expressions. This expression usually
     * starts with a single forward slash.
     *
     * Use {@see XPathAbstract::getExpressionItem()} to read this parameter.
     */
    public const XPATH_EXPRESSION_ITEM = '';

    /**
     * XPath expression for extracting an item title from the item context.
     * This expression should match a node contained within each article item node
     * containing the article headline. It should start with a dot followed by two
     * forward slashes, referring to any descendant nodes of the article item node.
     *
     * Use {@see XPathAbstract::getExpressionItemTitle()} to read this parameter.
     */
    public const XPATH_EXPRESSION_ITEM_TITLE = '';

    /**
     * XPath expression for extracting an item's content from the item context.
     * This expression should match a node contained within each article item node
     * containing the article content or description. It should start with a dot
     * followed by two forward slashes, referring to any descendant nodes of
     * the article item node.
     *
     * Use {@see XPathAbstract::getExpressionItemContent()} to read this parameter.
     */
    public const XPATH_EXPRESSION_ITEM_CONTENT = '';

    /**
     * XPath expression for extracting an item link from the item context.
     * This expression should match a node's attribute containing the article URL
     * (usually the href attribute of an <a> tag). It should start with a dot
     * followed by two forward slashes, referring to any descendant nodes of
     * the article item node. Attributes can be selected by prepending an @ char
     * before the attributes name.
     *
     * Use {@see XPathAbstract::getExpressionItemUri()} to read this parameter.
     */
    public const XPATH_EXPRESSION_ITEM_URI = '';

    /**
     * XPath expression for extracting an item author from the item context.
     * This expression should match a node contained within each article item
     * node containing the article author's name. It should start with a dot
     * followed by two forward slashes, referring to any descendant nodes of
     * the article item node.
     *
     * Use {@see XPathAbstract::getExpressionItemAuthor()} to read this parameter.
     */
    public const XPATH_EXPRESSION_ITEM_AUTHOR = '';

    /**
     * XPath expression for extracting an item timestamp from the item context.
     * This expression should match a node or node's attribute containing the
     * article timestamp or date (parsable by PHP's strtotime function). It
     * should start with a dot followed by two forward slashes, referring to
     * any descendant nodes of the article item node. Attributes can be
     * selected by prepending an @ char before the attributes name.
     *
     * Use {@see XPathAbstract::getExpressionItemTimestamp()} to read this parameter.
     */
    public const XPATH_EXPRESSION_ITEM_TIMESTAMP = '';

    /**
     * XPath expression for extracting item enclosures (media content like
     * images or movies) from the item context.
     * This expression should match a node's attribute containing an article
     * image URL (usually the src attribute of an <img> tag or a style
     * attribute). It should start with a dot followed by two forward slashes,
     * referring to any descendant nodes of the article item node. Attributes
     * can be selected by prepending an @ char before the attributes name.
     *
     * Use {@see XPathAbstract::getExpressionItemEnclosures()} to read this parameter.
     */
    public const XPATH_EXPRESSION_ITEM_ENCLOSURES = '';

    /**
     * XPath expression for extracting an item category from the item context.
     * This expression should match a node or node's attribute contained
     * within each article item node containing the article category. This
     * could be inside <div> or <span> tags or sometimes be hidden
     * in a data attribute. It should start with a dot followed by two
     * forward slashes, referring to any descendant nodes of the
     * article item node. Attributes can be selected by prepending an @ char
     * before the attributes name.
     *
     * Use {@see XPathAbstract::getExpressionItemCategories()} to read this parameter.
     */
    public const XPATH_EXPRESSION_ITEM_CATEGORIES = '';

    /**
     * Fix encoding.
     * Set this to true for fixing feed encoding by converting from UTF-8 to ISO-8859-1
     * function on all extracted texts. Try this in case you see "broken" or
     * "weird" characters in your feed where you'd normally expect umlauts
     * or any other non-ascii characters.
     *
     * Use {@see XPathAbstract::getSettingFixEncoding()} to read this parameter.
     */
    public const SETTING_FIX_ENCODING = false;

    /**
     * Use raw item content.
     * Whether to use the raw item content or to replace certain characters with
     * special significance in HTML by HTML entities (using the PHP function htmlspecialchars).
     *
     * Use {@see XPathAbstract::getSettingUseRawItemContent()} to read this parameter.
     */
    public const SETTING_USE_RAW_ITEM_CONTENT = true;

    /**
     * Internal storage for resulting feed name, automatically detected.
     */
    private ?string $feedName = null;

    /**
     * Internal storage for resulting feed uri, automatically detected.
     */
    private ?string $feedUri = null;

    /**
     * Internal storage for resulting feed favicon, automatically detected.
     */
    private ?string $feedIcon = null;

    /**
     * Returns the feed name.
     * Falls back to {@see BridgeAbstract::getName()} if no title was extracted.
     */
    public function getName(): string
    {
        return (empty($this->feedName) === false) ? $this->feedName : parent::getName();
    }

    /**
     * Returns the feed URI.
     * Falls back to {@see BridgeAbstract::getURI()} if no URI was detected.
     */
    public function getURI(): string
    {
        return (empty($this->feedUri) === false) ? $this->feedUri : parent::getURI();
    }

    /**
     * Returns the feed icon URL.
     * Falls back to {@see BridgeAbstract::getIcon()} if no icon was extracted.
     */
    public function getIcon(): string
    {
        return (empty($this->feedIcon) === false) ? $this->feedIcon : parent::getIcon();
    }

    /**
     * Returns the source Web page URL.
     */
    protected function getSourceUrl(): string
    {
        return static::FEED_SOURCE_URL;
    }

    /**
     * Returns the XPath expression for the feed title.
     */
    protected function getExpressionTitle(): string
    {
        return static::XPATH_EXPRESSION_FEED_TITLE;
    }

    /**
     * Returns the XPath expression for the feed favicon.
     */
    protected function getExpressionIcon(): string
    {
        return static::XPATH_EXPRESSION_FEED_ICON;
    }

    /**
     * Returns the XPath expression for the feed items.
     */
    protected function getExpressionItem(): string
    {
        return static::XPATH_EXPRESSION_ITEM;
    }

    /**
     * Returns the XPath expression for an item title.
     */
    protected function getExpressionItemTitle(): string
    {
        return static::XPATH_EXPRESSION_ITEM_TITLE;
    }

    /**
     * Returns the XPath expression for an item content.
     */
    protected function getExpressionItemContent(): string
    {
        return static::XPATH_EXPRESSION_ITEM_CONTENT;
    }

    /**
     * Returns the XPath expression for an item URI.
     */
    protected function getExpressionItemUri(): string
    {
        return static::XPATH_EXPRESSION_ITEM_URI;
    }

    /**
     * Returns the XPath expression for an item author.
     */
    protected function getExpressionItemAuthor(): string
    {
        return static::XPATH_EXPRESSION_ITEM_AUTHOR;
    }

    /**
     * Returns the XPath expression for an item timestamp.
     */
    protected function getExpressionItemTimestamp(): string
    {
        return static::XPATH_EXPRESSION_ITEM_TIMESTAMP;
    }

    /**
     * Returns the XPath expression for item enclosures.
     */
    protected function getExpressionItemEnclosures(): string
    {
        return static::XPATH_EXPRESSION_ITEM_ENCLOSURES;
    }

    /**
     * Returns the XPath expression for item categories.
     */
    protected function getExpressionItemCategories(): string
    {
        return static::XPATH_EXPRESSION_ITEM_CATEGORIES;
    }

    /**
     * Returns the setting for using raw item content.
     */
    protected function getSettingUseRawItemContent(): bool
    {
        return static::SETTING_USE_RAW_ITEM_CONTENT;
    }

    /**
     * Returns the setting for fixing feed encoding.
     */
    protected function getSettingFixEncoding(): bool
    {
        return static::SETTING_FIX_ENCODING;
    }

    /**
     * Internal helper method for quickly accessing all the user defined constants
     * in derived classes.
     *
     * @param string $name Parameter name
     */
    private function getParam(string $name): mixed
    {
        switch ($name) {
            case 'url':
                return $this->getSourceUrl();
            case 'feed_title':
                return $this->getExpressionTitle();
            case 'feed_icon':
                return $this->getExpressionIcon();
            case 'item':
                return $this->getExpressionItem();
            case 'title':
                return $this->getExpressionItemTitle();
            case 'content':
                return $this->getExpressionItemContent();
            case 'uri':
                return $this->getExpressionItemUri();
            case 'author':
                return $this->getExpressionItemAuthor();
            case 'timestamp':
                return $this->getExpressionItemTimestamp();
            case 'enclosures':
                return $this->getExpressionItemEnclosures();
            case 'categories':
                return $this->getExpressionItemCategories();
            case 'fix_encoding':
                return $this->getSettingFixEncoding();
            case 'raw_content':
                return $this->getSettingUseRawItemContent();
        }

        return null;
    }

    /**
     * Should provide the source website HTML content.
     * Can be easily overwritten for example if special headers or auth infos are required.
     */
    protected function provideWebsiteContent(): string
    {
        return getContents($this->feedUri);
    }

    /**
     * Should provide the feed's title.
     *
     * @param \DOMXPath $xpath
     */
    protected function provideFeedTitle(\DOMXPath $xpath): ?string
    {
        $title = $xpath->query($this->getParam('feed_title'));
        if (count($title) === 1) {
            return $this->fixEncoding($this->getItemValueOrNodeValue($title));
        }

        return null;
    }

    /**
     * Should provide the URL of the feed's favicon.
     *
     * @param \DOMXPath $xpath
     */
    protected function provideFeedIcon(\DOMXPath $xpath): ?string
    {
        $icon = $xpath->query($this->getParam('feed_icon'));
        if (count($icon) === 1) {
            return $this->cleanMediaUrl($this->getItemValueOrNodeValue($icon));
        }

        return null;
    }

    /**
     * Should provide the feed's items.
     *
     * @param \DOMXPath $xpath
     */
    protected function provideFeedItems(\DOMXPath $xpath): \DOMNodeList|false
    {
        return @$xpath->query($this->getParam('item'));
    }

    /**
     * Main entry point for collecting feed data.
     */
    public function collectData(): void
    {
        $this->feedUri = $this->getParam('url');

        $webPageHtml = new \DOMDocument();
        libxml_use_internal_errors(true);
        $webPageHtml->loadHTML($this->provideWebsiteContent());
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        defaultLinkTo($webPageHtml, $webPageHtml->baseURI ?? $this->feedUri);

        $xpath = new \DOMXPath($webPageHtml);

        $this->feedName = $this->provideFeedTitle($xpath);
        $this->feedIcon = $this->provideFeedIcon($xpath);

        $entries = $this->provideFeedItems($xpath);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            $item = [];
            $parameters = [
                'title',
                'content',
                'uri',
                'author',
                'timestamp',
                'enclosures',
                'categories',
            ];

            foreach ($parameters as $parameter) {
                $expression = $this->getParam($parameter);
                if ('' === $expression) {
                    continue;
                }

                $typedResult = @$xpath->evaluate($expression, $entry);
                if (
                    $typedResult === false
                    || (($typedResult instanceof \DOMNodeList) === true && count($typedResult) === 0)
                    || (is_string($typedResult) === true && strlen(trim($typedResult)) === 0)
                ) {
                    continue;
                }

                if ('categories' === $parameter && $typedResult instanceof \DOMNodeList) {
                    $value = [];
                    foreach ($typedResult as $domNode) {
                        $value[] = $this->getItemValueOrNodeValue($domNode, false);
                    }
                } else {
                    $value = $this->getItemValueOrNodeValue($typedResult, 'content' === $parameter);
                }

                $item[$parameter] = $this->formatParamValue($parameter, $value);
            }

            $itemId = $this->generateItemId($item);
            if (null !== $itemId) {
                $item['uid'] = $itemId;
            }

            $this->items[] = $item;
        }
    }

    /**
     * Formats a parameter value before it is added to the item array.
     *
     * @param string $param Parameter name
     * @param string|array $value Extracted value
     */
    protected function formatParamValue(string $param, mixed $value): mixed
    {
        $value = (is_array($value) === true) ? array_map('trim', $value) : trim($value);
        $value = (is_array($value) === true) ? array_map([$this, 'fixEncoding'], $value) : $this->fixEncoding($value);

        switch ($param) {
            case 'title':
                return $this->formatItemTitle($value);
            case 'content':
                return $this->formatItemContent($value);
            case 'uri':
                return $this->formatItemUri($value);
            case 'author':
                return $this->formatItemAuthor($value);
            case 'timestamp':
                return $this->formatItemTimestamp($value);
            case 'enclosures':
                return $this->formatItemEnclosures($value);
            case 'categories':
                return $this->formatItemCategories($value);
        }

        return $value;
    }

    /**
     * Formats the title of a feed item.
     * Can be easily overwritten in case the value needs to be transformed.
     *
     * @param string $value Raw title
     */
    protected function formatItemTitle(string $value): string
    {
        return $value;
    }

    /**
     * Formats the content of a feed item.
     * Can be easily overwritten in case the value needs to be transformed.
     *
     * @param string $value Raw content
     */
    protected function formatItemContent(string $value): string
    {
        return ($this->getParam('raw_content') === true) ? $value : htmlspecialchars($value);
    }

    /**
     * Formats the URI of a feed item.
     * Can be easily overwritten in case the value needs to be transformed.
     *
     * @param string $value Raw URI
     */
    protected function formatItemUri(string $value): string
    {
        if (strlen($value) === 0) {
            return '';
        }

        if (str_starts_with($value, 'http://') === true || str_starts_with($value, 'https://') === true) {
            return $value;
        }

        return urljoin($this->feedUri, $value);
    }

    /**
     * Formats the author of a feed item.
     * Can be easily overwritten in case the value needs to be transformed.
     *
     * @param string $value Raw author
     */
    protected function formatItemAuthor(string $value): string
    {
        return $value;
    }

    /**
     * Formats the timestamp of a feed item.
     * Takes extracted raw timestamp and returns unix timestamp as integer.
     * Can be easily overwritten for example if a special format has to be expected
     * on the source website.
     *
     * @param string $value Raw timestamp
     * @return int|false
     */
    protected function formatItemTimestamp(string $value): int|false
    {
        return strtotime($value);
    }

    /**
     * Formats the enclosures of a feed item.
     * Can be easily overwritten in case the values need to be transformed.
     *
     * @param string $value Raw enclosure URL
     */
    protected function formatItemEnclosures(string $value): array
    {
        return [$this->cleanMediaUrl($value)];
    }

    /**
     * Formats the categories of a feed item.
     * Can be easily overwritten in case the values need to be transformed.
     *
     * @param string|array $value Raw categories
     */
    protected function formatItemCategories(mixed $value): array
    {
        return (is_array($value) === true) ? $value : [$value];
    }

    /**
     * Cleans a media URL and joins it with the feed URI if necessary.
     *
     * @param string $mediaUrl Raw media URL
     */
    protected function cleanMediaUrl(string $mediaUrl): ?string
    {
        $pattern = '~(?:http(?:s)?:)?[\/a-zA-Z0-9\-=_,\.\%]+\.(?:jpg|gif|png|jpeg|ico|mp3|webp){1}~i';
        $result = preg_match($pattern, $mediaUrl, $matches);
        if (1 !== $result) {
            return null;
        }

        return urljoin($this->feedUri, $matches[0]);
    }

    /**
     * Extracts a value from a typed result (DOMNodeList, DOMElement, DOMAttr, etc.).
     *
     * @param mixed $typedResult Result from XPath evaluation
     * @param bool $returnXML Whether to return the node as XML
     *
     * @throws \Exception
     */
    protected function getItemValueOrNodeValue(mixed $typedResult, bool $returnXML = false): string
    {
        if ($typedResult instanceof \DOMNodeList) {
            $typedResult = $typedResult->item(0);
        }

        if ($typedResult instanceof \DOMElement) {
            return ($returnXML === true) ? ($typedResult->ownerDocument ?? $typedResult)->saveXML($typedResult) : $typedResult->nodeValue;
        } elseif ($typedResult instanceof \DOMAttr) {
            return $typedResult->value;
        } elseif ($typedResult instanceof \DOMText) {
            return $typedResult->wholeText;
        } elseif (is_string($typedResult) === true) {
            return $typedResult;
        } elseif ($typedResult === null) {
            return '';
        }

        throw new \Exception('Unknown type of XPath expression result: ' . gettype($typedResult));
    }

    /**
     * Fixes feed encoding by converting extracted texts from UTF-8 to ISO-8859-1.
     * Useful in case of "broken" or "weird" characters in the feed where you'd normally
     * expect umlauts or other non-ASCII characters.
     *
     * @param string $input Input string
     */
    protected function fixEncoding(string $input): string
    {
        return ($this->getParam('fix_encoding') === true) ? mb_convert_encoding($input, 'ISO-8859-1', 'UTF-8') : $input;
    }

    /**
     * Allows overriding default mechanism for determining items UIDs.
     *
     * @param array $item Item data
     */
    protected function generateItemId(array $item): ?string
    {
        return null;
    }
}
