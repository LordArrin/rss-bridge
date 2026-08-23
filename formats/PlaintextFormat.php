<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

/**
 * Plaintext format for debugging.
 * Outputs feed data as formatted text.
 */
final class PlaintextFormat extends FormatAbstract
{
    public const MIME_TYPE = 'text/plain';

    public function getMimeType(): string
    {
        return self::MIME_TYPE;
    }

    public function render(): string
    {
        $feed = $this->getFeed();

        foreach ($this->getItems() as $item) {
            $feed['items'][] = $item->toArray();
        }

        return print_r($feed, true);
    }
}
