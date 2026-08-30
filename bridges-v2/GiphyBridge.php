<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class GiphyBridge extends BridgeAbstract
{
    public const NAME = 'Giphy';
    public const URI = 'https://giphy.com/';
    public const DESCRIPTION = 'Bridge for giphy.com';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 28800;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            's' => [
                'name' => 'search tag',
                'exampleValue' => 'bird',
                'required' => true
            ],
            'noGif' => [
                'name' => 'Without gifs',
                'type' => 'checkbox',
                'title' => 'Exclude gifs from the results'
            ],
            'noStick' => [
                'name' => 'Without stickers',
                'type' => 'checkbox',
                'title' => 'Exclude stickers from the results'
            ],
            'n' => [
                'name' => 'max number of returned items (max 50)',
                'type' => 'number',
                'exampleValue' => 3,
            ]
        ]
    ];

    public function getName(): string
    {
        $searchInput = $this->getInput('s');
        if (is_string($searchInput) === true && $searchInput !== '') {
            return $searchInput . ' - ' . parent::getName();
        }

        return parent::getName();
    }

    private function getGiphyItems(array $entries): void
    {
        foreach ($entries as $entry) {
            if (is_object($entry) === false) {
                continue;
            }

            $id = (string) ($entry->id ?? '');
            $url = (string) ($entry->url ?? '');
            $title = (string) ($entry->title ?? '');
            $username = (string) ($entry->username ?? '');

            if ($url === '') {
                continue;
            }

            $importDatetime = (string) ($entry->import_datetime ?? '');
            $timestamp = ($importDatetime !== '') ? strtotime($importDatetime) : false;

            $imageUrl = '';
            $images = $entry->images ?? null;
            if (is_object($images) === true) {
                if (isset($images->downsized) === true && is_object($images->downsized) === true) {
                    $imageUrl = (string) ($images->downsized->url ?? '');
                } elseif (isset($images->fixed_height) === true && is_object($images->fixed_height) === true) {
                    $imageUrl = (string) ($images->fixed_height->url ?? '');
                } elseif (isset($images->original) === true && is_object($images->original) === true) {
                    $imageUrl = (string) ($images->original->url ?? '');
                }
            }

            $content = '';
            if ($imageUrl !== '') {
                $content .= '<a href="' . htmlspecialchars($url) . '">';
                $content .= '<img src="' . htmlspecialchars($imageUrl) . '" loading="lazy" style="' . self::CSS['img'] . '" alt="" />';
                $content .= '</a>';
            }

            $item = [
                'uid' => $id,
                'uri' => $url,
                'author' => $username,
                'title' => $title,
                'content' => $content,
            ];

            if ($timestamp !== false && $timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }

    public function collectData(): void
    {
        $apiKey = 'Gc7131jiJuvI7IdN0HZ1D7nh0ow5BU6g';
        $bundle = 'low_bandwidth';

        $limitInput = $this->getInput('n');
        if (is_numeric($limitInput) === true) {
            $limit = min((int) $limitInput, 50);
        } else {
            $limit = 10;
        }

        $noGifInput = $this->getInput('noGif');
        $noGif = ($noGifInput === true || $noGifInput === 'on' || $noGifInput === 1 || $noGifInput === '1');

        $noStickInput = $this->getInput('noStick');
        $noStick = ($noStickInput === true || $noStickInput === 'on' || $noStickInput === 1 || $noStickInput === '1');

        $endpoints = [];
        if ($noGif === false) {
            $endpoints[] = 'gifs';
        }
        if ($noStick === false) {
            $endpoints[] = 'stickers';
        }

        $searchInput = (string) ($this->getInput('s') ?? '');
        if ($searchInput === '') {
            \throwClientException('Search tag is required');
        }

        foreach ($endpoints as $endpoint) {
            $uri = sprintf(
                'https://api.giphy.com/v1/%s/search?q=%s&limit=%s&bundle=%s&api_key=%s',
                $endpoint,
                rawurlencode($searchInput),
                (string) $limit,
                $bundle,
                $apiKey
            );

            $raw = getContents($uri);
            if (is_string($raw) === false || $raw === '') {
                continue;
            }

            $result = json_decode($raw, false);
            if (is_object($result) === false) {
                continue;
            }

            $data = $result->data ?? null;
            if (is_array($data) === false) {
                continue;
            }

            $this->getGiphyItems($data);
        }

        usort($this->items, function (array $a, array $b): int {
            $tsA = $a['timestamp'] ?? 0;
            $tsB = $b['timestamp'] ?? 0;
            return $tsB <=> $tsA;
        });
    }
}
