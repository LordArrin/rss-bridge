<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class AppleMusicBridge extends BridgeAbstract
{
    public const NAME = 'Apple Music';
    public const URI = 'https://www.apple.com';
    public const DESCRIPTION = 'Fetches the latest releases from an artist';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 21600;

    public const PARAMETERS = [[
        'artist' => [
            'name' => 'Artist ID',
            'exampleValue' => '909253',
            'required' => true,
        ],
        'limit' => [
            'name' => 'Latest X Releases (max 50)',
            'defaultValue' => '10',
            'required' => true,
        ],
    ]];

    private const API_URL = 'https://itunes.apple.com/lookup';
    private const MAX_LIMIT = 50;

    private ?string $title = null;

    public function collectData()
    {
        $items = $this->getJson();
        $artist = $this->getArtist($items);

        if ($artist !== null && isset($artist->artistName) === true) {
            $this->title = (string) $artist->artistName;
        }

        foreach ($items as $item) {
            if (is_object($item) === false) {
                continue;
            }

            if (($item->wrapperType ?? '') !== 'collection') {
                continue;
            }

            $collectionName = (string)($item->collectionName ?? '');
            if ($collectionName === '') {
                continue;
            }

            $collectionViewUrl = (string)($item->collectionViewUrl ?? '');
            if ($collectionViewUrl === '') {
                continue;
            }

            $copyright = (string)($item->copyright ?? '');
            $artistName = (string)($item->artistName ?? '');
            $releaseDate = $item->releaseDate ?? null;

            $content = $this->buildItemContent($copyright);

            $this->items[] = [
                'title' => $collectionName,
                'uri' => $collectionViewUrl,
                'timestamp' => $releaseDate,
                'author' => $artistName !== '' ? $artistName : null,
                'content' => $content,
            ];
        }
    }

    public function getName()
    {
        if ($this->title !== null) {
            return $this->title;
        }

        return parent::getName();
    }

    private function getJson(): array
    {
        if ($artist !== null && isset($artist->artistName) === true) {
            $this->title = (string) $artist->artistName;
        }

        $limitInput = $this->getInput('limit');
        $limit = 10;
        if (is_numeric($limitInput) === true) {
            $limit = (int) $limitInput;
        }
        $limit = min(max($limit, 1), self::MAX_LIMIT);

        $artistId = (string)$this->getInput('artist');

        $params = http_build_query([
            'id' => $artistId,
            'entity' => 'album',
            'limit' => $limit,
            'sort' => 'recent',
        ]);

        $url = self::API_URL . '?' . $params;
        $html = getContents($url);

        $json = json_decode($html, false);
        if (is_object($json) === false || isset($json->results) === false || is_array($json->results) === false) {
            throwServerException('There is no artist with id "' . $artistId . '".');
        }

        if (count($json->results) === 0) {
            throwServerException('There is no artist with id "' . $artistId . '".');
        }

        return $json->results;
    }

    private function getArtist(array $json): ?object
    {
        foreach ($json as $obj) {
            if (is_object($obj) === false) {
                continue;
            }

            if (($obj->wrapperType ?? '') === 'artist') {
                return $obj;
            }
        }

        return null;
    }

    private function buildItemContent(string $copyright): string
    {
        if ($copyright === '') {
            return '';
        }

        return '<p>' . e($copyright) . '</p>';
    }
}
