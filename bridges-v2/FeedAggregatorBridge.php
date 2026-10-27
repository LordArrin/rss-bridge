<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\LocalHost;
use RSSBridge\Utils\Json;

final class FeedAggregatorBridge extends BridgeAbstract
{
    public const NAME = 'Feed Aggregator';
    public const URI = 'https://github.com/LordArrin/rss-bridge';
    public const DESCRIPTION = 'Combines multiple local RSS-Bridge feeds into a single sorted feed. Only accepts URLs from the configured DOMAIN';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        '' => [
            'feed_1' => [
                'name' => 'Feed URL #1',
                'type' => 'text',
                'required' => true,
            ],
            'feed_2' => [
                'name' => 'Feed URL #2',
                'type' => 'text',
                'required' => false,
            ],
            'feed_3' => [
                'name' => 'Feed URL #3',
                'type' => 'text',
                'required' => false,
            ],
            'feed_4' => [
                'name' => 'Feed URL #4',
                'type' => 'text',
                'required' => false,
            ],
            'feed_5' => [
                'name' => 'Feed URL #5',
                'type' => 'text',
                'required' => false,
            ],
            'limit' => self::LIMIT,
        ],
    ];

    public function collectData(): void
    {
        $urls = [];
        for ($i = 1; $i <= 5; $i++) {
            $url = $this->getInput('feed_' . $i);
            if (is_string($url) === true && $url !== '') {
                $urls[] = $url;
            }
        }

        if ($urls === []) {
            throwClientException('At least one feed URL is required');
        }

        $allItems = [];
        $limitInput = $this->getInput('limit');
        $limit = ($limitInput !== null && (int) $limitInput > 0) ? (int) $limitInput : 50;

        foreach ($urls as $url) {
            $this->validateLocalDomain($url);

            $jsonUrl = $this->normalizeFeedUrl($url, 'Json');

            try {
                $body = LocalHost::getContents($jsonUrl);
                if ($body === '') {
                    continue;
                }

                $data = Json::decode($body);
                $items = $data['items'] ?? $data['entries'] ?? [];

                if (is_array($items) === true) {
                    foreach ($items as $item) {
                        $title = $item['title'] ?? 'Aggregated Item';
                        $uri = $item['uri'] ?? $item['url'] ?? '';

                        $author = $item['author'] ?? '';
                        $hasAuthors = isset($item['authors']);
                        if ($author === '' && $hasAuthors === true && is_array($item['authors']) === true) {
                            $authorNames = array_column($item['authors'], 'name');
                            $author = implode(', ', array_filter($authorNames, static fn($name): bool => is_string($name) === true && $name !== ''));
                        }

                        $timestamp = $item['timestamp'] ?? null;
                        $hasDateModified = isset($item['date_modified']);
                        if ($timestamp === null && $hasDateModified === true && is_string($item['date_modified']) === true) {
                            $parsedTime = strtotime($item['date_modified']);
                            if ($parsedTime !== false) {
                                $timestamp = $parsedTime;
                            }
                        }

                        $content = $item['content'] ?? $item['content_html'] ?? '';

                        if (is_string($content) === true && $content !== '') {
                            $content = raw($content);
                        }

                        $originalId = $uri !== '' ? $uri : ($title . $content);
                        $uid = md5($jsonUrl . '|' . (string) $originalId);

                        $allItems[] = [
                            'title' => $title,
                            'uri' => $uri,
                            'author' => $author !== '' ? $author : null,
                            'timestamp' => $timestamp,
                            'content' => $content,
                            'uid' => $uid,
                        ];
                    }
                }
            } catch (\JsonException $e) {
                $this->logger->warning(sprintf('FeedAggregator: JSON parse error for %s', $jsonUrl));
            } catch (\Exception $e) {
                $this->logger->warning(sprintf('FeedAggregator: failed to fetch %s', $jsonUrl));
            }
        }

        usort($allItems, static fn(array $a, array $b): int => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
        $this->items = array_slice($allItems, 0, $limit);
    }

    /**
     * Validates that the provided URL belongs to the configured DOMAIN.
     * Throws generic ClientException (HTTP 400) to prevent information disclosure.
     */
    private function validateLocalDomain(string $url): void
    {
        $domainEnv = getenv('DOMAIN');
        if ($domainEnv === false || $domainEnv === '') {
            throwServerException('DOMAIN environment variable is not configured.');
        }

        $parsedDomain = parse_url($domainEnv);
        $domainHasHost = isset($parsedDomain['host']);
        if (is_array($parsedDomain) === false || $domainHasHost === false) {
            throwServerException('Invalid DOMAIN environment variable format.');
        }

        $expectedHost = strtolower((string) $parsedDomain['host']);
        $domainHasPort = isset($parsedDomain['port']);
        $expectedPort = $domainHasPort === true ? (int) $parsedDomain['port'] : null;

        $parsedUrl = parse_url($url);
        $urlHasHost = isset($parsedUrl['host']);
        if (is_array($parsedUrl) === false || $urlHasHost === false) {
            throwClientException('Invalid URL format provided.');
        }

        $inputHost = strtolower((string) $parsedUrl['host']);
        $urlHasPort = isset($parsedUrl['port']);
        $inputPort = $urlHasPort === true ? (int) $parsedUrl['port'] : null;

        if ($inputHost !== $expectedHost) {
            throwClientException('Cross-domain feeds are not allowed for security reasons.');
        }

        if ($expectedPort !== null && $inputPort !== $expectedPort) {
            throwClientException('Invalid URL port specified.');
        }
    }

    private function normalizeFeedUrl(string $url, string $targetFormat): string
    {
        $parts = explode('?', $url, 2);
        $baseUrl = $parts[0];
        $queryString = $parts[1] ?? '';

        parse_str($queryString, $params);
        unset($params['format']);
        $params['format'] = $targetFormat;

        return $baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
