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

    /**
     * @var array<string, mixed>
     */
    protected array $feed = [];

    /**
     * @var FeedItem[]
     */
    protected array $items = [];

    protected int $lastModified = 0;

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $feed
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
     *
     * @return array<string, mixed>
     */
    public function getFeed(): array
    {
        return $this->feed;
    }

    /**
     * {@inheritdoc}
     *
     * Accepts both FeedItem instances and raw arrays.
     *
     * @param list<FeedItem|array<string, mixed>> $items
     */
    public function setItems(array $items): void
    {
        $this->items = [];
        foreach ($items as $item) {
            if ($item instanceof FeedItem) {
                $this->items[] = $item;
            } else {
                $this->items[] = FeedItem::fromArray((array) $item);
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
