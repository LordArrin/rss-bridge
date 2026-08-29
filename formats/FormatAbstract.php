<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

use RSSBridge\FeedItem;

/**
 * Base class for all feed formats.
 *
 * Provides common storage and helpers for feed metadata and items.
 * Concrete formats must implement render() and define MIME_TYPE constant.
 */
abstract class FormatAbstract implements FormatInterface
{
    /**
     * MIME type - subclasses MUST override this constant.
     */
    public const MIME_TYPE = 'text/plain';

    protected array $feed = [];

    /**
     * @var FeedItem[]
     */
    protected array $items = [];

    protected int $lastModified = 0;

    /**
     * {@inheritdoc}
     */
    public function setFeed(array $feed): void
    {
        $default = [
            'name'        => '',
            'uri'         => '',
            'icon'        => '',
        ];
        $this->feed = array_merge($default, $feed);
    }

    /**
     * {@inheritdoc}
     */
    public function getFeed(): array
    {
        return $this->feed;
    }

    /**
     * {@inheritdoc}
     *
     * Accepts both FeedItem instances and raw arrays.
     */
    public function setItems(array $items): void
    {
        $this->items = [];
        foreach ($items as $item) {
            if ($item instanceof FeedItem) {  // < ÓÁÐÀÒÜ ÎÁÐÀÒÍÛÉ ÑËÅØ
                $this->items[] = $item;
            } else {
                $this->items[] = FeedItem::fromArray((array) $item);  // < ÓÁÐÀÒÜ ÎÁÐÀÒÍÛÉ ÑËÅØ
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return FeedItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * {@inheritdoc}
     */
    public function getMimeType(): string
    {
        return static::MIME_TYPE;
    }

    /**
     * {@inheritdoc}
     */
    public function setLastModified(int $lastModified): void
    {
        $this->lastModified = $lastModified;
    }

    /**
     * Get the last modification timestamp.
     */
    protected function getLastModified(): int
    {
        return $this->lastModified;
    }
}
