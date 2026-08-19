<?php

declare(strict_types=1);

use RSSBridge\Formats\MrssFormat;
use RSSBridge\Formats\AtomFormat;

/**
 * Expands an existing feed
 *
 * Note: donationUri is parsed but not used in FeedExpander output.
 * Donations support is disabled in current implementation.
 */
abstract class FeedExpander extends BridgeAbstract
{
    private array $feed = [];

    public function collectExpandableDatas(string $url, int $maxItems = -1, array $headers = []): void
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
            // FeedMergeBridge relies on this string
            throw new \Exception(sprintf('Failed to parse xml from %s: %s', $url, create_sane_exception_message($e)));
        }

        $items = array_slice($this->feed['items'] ?? [], 0, $maxItems);
        
        // todo: extract parse logic out from FeedParser
        foreach ($items as $item) {
            // Give bridges a chance to modify the item
            $item = $this->parseItem($item);
            if ($item) {
                $this->items[] = $item;
            }
        }
    }

    /**
     * This method is overridden by bridges
     *
     * @param array $item
     * @return array
     */
    protected function parseItem(array $item): array
    {
        return $item;
    }

    public function getURI(): string
    {
        return $this->feed['uri'] ?? parent::getURI();
    }

    public function getName(): string
    {
        return $this->feed['title'] ?? parent::getName();
    }

    public function getIcon(): string
    {
        return $this->feed['icon'] ?? parent::getIcon();
    }
}
