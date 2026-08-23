<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

interface ProxyInterface
{
    /**
     * Fetch HTML via this proxy.
     *
     * @param string $url URL to fetch
     * @param array $options Proxy-specific options (cookies, timeout, wait, use_cache, cache_ttl)
     * @return string HTML content
     */
    public function getHtml(string $url, array $options = []): string;

    /**
     * Fetch binary content (images, video, files) via this proxy.
     *
     * @return array{body: string, type: string} ['body' => raw bytes, 'type' => MIME type]
     */
    public function getBinary(string $url, array $options = []): array;

    /**
     * Check if this proxy is configured and reachable.
     */
    public function isAvailable(): bool;

    /**
     * Proxy name for logs.
     */
    public function getName(): string;
}
