<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

/**
 * Interface for all feed formats.
 *
 * Formats convert bridge items into syndication standards
 * (Atom, RSS, JSON Feed, HTML, etc.)
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
     * @param array{name?: string, uri?: string, icon?: string, donationUri?: string} $feed
     */
    public function setFeed(array $feed): void;

    /**
     * Get the feed metadata.
     */
    public function getFeed(): array;

    /**
     * Set the feed items.
     *
     * @param \FeedItem[]|array[] $items
     */
    public function setItems(array $items): void;

    /**
     * Get the feed items.
     *
     * @return \FeedItem[]
     */
    public function getItems(): array;

    /**
     * Set the last modification timestamp (Unix epoch).
     */
    public function setLastModified(int $lastModified): void;
}
