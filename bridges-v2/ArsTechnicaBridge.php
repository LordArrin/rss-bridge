<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\FeedParser;

final class ArsTechnicaBridge extends BridgeAbstract
{
    public const NAME = 'Ars Technica';
    public const URI = 'https://arstechnica.com/';
    public const DESCRIPTION = 'Ars Technica news feeds';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        '' => [
            'channel' => [
                'name' => 'Channel',
                'type' => 'list',
                'values' => [
                    'All' => 'all',
                    'Technology' => 'technology',
                    'Science' => 'science',
                    'Gaming' => 'gaming',
                    'Business' => 'business',
                    'Cars' => 'cars',
                    'Staff' => 'staff',
                ],
                'defaultValue' => 'all',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => false,
                'defaultValue' => 10,
                'title' => 'Maximum number of articles to fetch',
            ],
        ],
    ];

    private const FEED_URLS = [
        'all' => 'https://feeds.arstechnica.com/arstechnica/index',
        'technology' => 'https://feeds.arstechnica.com/arstechnica/technology-lab',
        'science' => 'https://feeds.arstechnica.com/arstechnica/science',
        'gaming' => 'https://feeds.arstechnica.com/arstechnica/gaming',
        'business' => 'https://feeds.arstechnica.com/arstechnica/business',
        'cars' => 'https://feeds.arstechnica.com/arstechnica/cars',
        'staff' => 'https://feeds.arstechnica.com/arstechnica/staff',
    ];

    public function collectData(): void
    {
        $channel = (string)$this->getInput('channel');
        if ($channel === '') {
            $channel = 'all';
        }

        $limitInput = $this->getInput('limit');
        $limit = $limitInput !== null && $limitInput !== '' ? (int)$limitInput : 10;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $feedUrl = self::FEED_URLS[$channel] ?? self::FEED_URLS['all'];

        $feedContent = getContents($feedUrl);
        if ($feedContent === '') {
            throwServerException('Failed to fetch RSS feed from Ars Technica');
        }

        $parser = new FeedParser();
        $feed = $parser->parseFeed($feedContent);

        if ($feed === null) {
            throwServerException('Failed to parse Ars Technica RSS feed');
        }

        $count = 0;
        foreach ($feed['items'] as $item) {
            if ($count >= $limit) {
                break;
            }

            $articleUrl = $item['uri'] ?? '';
            if ($articleUrl === '') {
                continue;
            }

            $title = $item['title'] ?? 'Untitled';
            $timestamp = $item['timestamp'] ?? time();
            $author = $item['author'] ?? null;
            $content = $item['content'] ?? '';

            $this->items[] = [
                'title' => $title,
                'uri' => $articleUrl,
                'content' => $content,
                'timestamp' => $timestamp,
                'author' => $author,
                'uid' => $articleUrl,
            ];

            $count++;
        }
    }

    public function getName(): string
    {
        $channel = (string)$this->getInput('channel');
        if ($channel === '' || $channel === 'all') {
            return parent::getName();
        }

        $channelNames = [
            'technology' => 'Technology',
            'science' => 'Science',
            'gaming' => 'Gaming',
            'business' => 'Business',
            'cars' => 'Cars',
            'staff' => 'Staff',
        ];

        $name = $channelNames[$channel] ?? $channel;
        return parent::getName() . ' - ' . $name;
    }

    public function getURI(): string
    {
        $channel = (string)$this->getInput('channel');
        if ($channel === '' || $channel === 'all') {
            return parent::getURI();
        }

        $channelUris = [
            'technology' => 'https://arstechnica.com/technology/',
            'science' => 'https://arstechnica.com/science/',
            'gaming' => 'https://arstechnica.com/gaming/',
            'business' => 'https://arstechnica.com/business/',
            'cars' => 'https://arstechnica.com/cars/',
            'staff' => 'https://arstechnica.com/staff/',
        ];

        return $channelUris[$channel] ?? parent::getURI();
    }
}
