<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

use RSSBridge\FeedItem;

/**
 * Interface for all feed formats.
 *
 * Formats convert bridge items into syndication standards
 * (Atom, RSS, JSON Feed, HTML, etc.)
 *
 * render() must be deterministic for a given (feed, items, lastModified)
 * triple: it must not read the wall clock, so the same cached payload always
 * produces a byte-identical body and a stable ETag.
 */
interface FormatInterface
{
    /**
     * Render the feed as a string.
     */
    public function render(): string;

    /**
     * Get the MIME type for this format.
     */
    public function getMimeType(): string;

    /**
     * Set the feed metadata (name, uri, icon, etc.).
     *
     * @param array<string, mixed> $feed
     */
    public function setFeed(array $feed): void;

    /**
     * Get the feed metadata.
     *
     * @return array<string, mixed>
     */
    public function getFeed(): array;

    /**
     * Set the feed items.
     *
     * @param list<FeedItem|array<string, mixed>> $items
     */
    public function setItems(array $items): void;

    /**
     * Get the feed items.
     *
     * @return list<FeedItem>
     */
    public function getItems(): array;

    /**
     * Set the last modification timestamp (Unix epoch).
     *
     * DisplayAction supplies a stable, content-derived anchor (newest item
     * timestamp, else the data generation time). 0 means "not set" and is only
     * expected when a format is used outside the display pipeline.
     */
    public function setLastModified(int $lastModified): void;
}
