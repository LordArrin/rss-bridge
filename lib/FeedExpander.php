<?php

declare(strict_types=1);

use RSSBridge\Formats\MrssFormat;
use RSSBridge\Formats\AtomFormat;

/**
 * Abstract base class for bridges that expand an existing RSS/Atom feed.
 *
 * This class fetches a remote feed, parses it with {@see FeedParser},
 * and passes each item through {@see parseItem()} so that the extending
 * bridge can enrich or modify the item (e.g. fetch the full article body
 * from the original website).
 *
 * Note: donationUri is parsed but not used in FeedExpander output.
 * Donations support is disabled in the current implementation.
 */
abstract class FeedExpander extends BridgeAbstract
{
    /**
     * Parsed feed data returned by {@see FeedParser::parseFeed()}.
     *
     * @var array
     */
    private $feed = [];

    /**
     * Fetches and parses the remote feed, populating the internal items list.
     *
     * Each parsed item is passed to {@see parseItem()} which may enrich it.
     * Only items that evaluate to truthy after parsing are kept.
     *
     * @param string $url Feed URL to fetch
     * @param int $maxItems Maximum number of items to keep (-1 for unlimited)
     * @param array $headers Additional HTTP headers for the request
     * @throws \Exception If the URL is empty, the response is empty, or the XML cannot be parsed
     */
    public function collectExpandableDatas($url, $maxItems = -1, $headers = [])
    {
        if (!$url) {
            throw new \Exception('There is no $url for this RSS expander');
        }

        if ($maxItems === -1) {
            $maxItems = 999;
        }

        $accept = [MrssFormat::MIME_TYPE, AtomFormat::MIME_TYPE, '*/*'];
        $httpHeaders = array_merge(['Accept: ' . implode(', ', $accept)], $headers);
        $xmlString = getContents($url, $httpHeaders);

        if ($xmlString === '') {
            throw new \Exception(sprintf('Unable to parse xml from `%s` because we got the empty string', $url), 10);
        }

        $feedParser = new FeedParser();
        try {
            $this->feed = $feedParser->parseFeed($xmlString);
        } catch (\Exception $e) {
            // FeedMergeBridge relies on this specific message format
            throw new \Exception(sprintf('Failed to parse xml from %s: %s', $url, create_sane_exception_message($e)));
        }

        $items = array_slice($this->feed['items'] ?? [], 0, $maxItems);

        foreach ($items as $item) {
            $item = $this->parseItem($item);
            if ($item) {
                $this->items[] = $item;
            }
        }
    }

    /**
     * Processes a single parsed feed item.
     *
     * Override this method in extending bridges to enrich the item
     * (for example, by fetching the full article HTML from the item's URI).
     *
     * IMPORTANT: The signature is intentionally left without type hints
     * to remain compatible with legacy bridges that override this method
     * with their own untyped signatures.
     *
     * @param array $item Parsed feed item
     * @return array The (possibly modified) item, or a falsy value to drop it
     */
    protected function parseItem($item)
    {
        return $item;
    }

    /**
     * Returns the feed URI, preferring the value from the parsed feed
     * and falling back to the parent implementation.
     */
    public function getURI()
    {
        return $this->feed['uri'] ?? parent::getURI();
    }

    /**
     * Returns the feed title, preferring the value from the parsed feed
     * and falling back to the parent implementation.
     */
    public function getName()
    {
        return $this->feed['title'] ?? parent::getName();
    }

    /**
     * Returns the feed icon URL, preferring the value from the parsed feed
     * and falling back to the parent implementation.
     */
    public function getIcon()
    {
        return $this->feed['icon'] ?? parent::getIcon();
    }
}
