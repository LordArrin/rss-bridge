<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class KitsuBridge extends BridgeAbstract
{
    public const NAME = 'Kitsu Episode Updates';
    public const URI = 'https://kitsu.app';
    public const DESCRIPTION = 'Lists latest upcoming episodes';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'Episodes from all shows' => [],
        'By show id' => [
            'id' => [
                'name' => 'Show id',
                'type' => 'number',
                'title' => 'Specify the id of the anime show as provided by the api',
                'exampleValue' => '43806',
                'required' => true,
            ],
        ],
        'By show name' => [
            'name' => [
                'name' => 'Show name',
                'title' => 'Copy & paste the exact name from show URL',
                'exampleValue' => 'Chainsaw Man',
                'required' => true,
            ],
        ],
        'By show url path' => [
            'url_path' => [
                'name' => 'Show URL path',
                'title' => 'Copy & paste the exact name from show URL',
                'exampleValue' => 'chainsaw-man',
                'required' => true,
            ],
        ],
        'global' => [
            'number_of_items' => [
                'name' => 'Number of items',
                'type' => 'number',
                'title' => 'Specify the number of items in the resulting feed (max 20)',
                'exampleValue' => 20,
            ],
        ],
    ];

    private const MAX_PAGE_SIZE = 20;
    private const DEFAULT_PAGE_SIZE = 20;

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    public function collectData()
    {
        $pageSize = $this->getPageSize();
        $urlApi = $this->buildApiUrl($pageSize);

        $feedContent = json_decode(getContents($urlApi), true);

        if (is_array($feedContent) === false || isset($feedContent['data']) === false || is_array($feedContent['data']) === false) {
            throwServerException('Invalid API response');
        }

        $animeList = $this->buildAnimeList($feedContent);

        foreach ($feedContent['data'] as $episode) {
            if (is_array($episode) === false) {
                continue;
            }

            $item = $this->buildItem($episode, $animeList);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }

        usort($this->items, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
    }

    private function getPageSize(): int
    {
        $numberOfItems = $this->getInput('number_of_items');

        if (is_numeric($numberOfItems) === false) {
            return self::DEFAULT_PAGE_SIZE;
        }

        $pageSize = (int)$numberOfItems;

        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return $pageSize;
    }

    private function buildApiUrl(int $pageSize): string
    {
        $id = $this->getInput('id');
        $name = $this->getInput('name');
        $urlPath = $this->getInput('url_path');

        if ($id !== null && is_string($id) === true && ctype_digit($id) === true) {
            return $this->buildEpisodesApiUrl((int)$id, $pageSize);
        }

        if ($name !== null || $urlPath !== null) {
            $animeId = $this->findAnimeId($name, $urlPath);
            return $this->buildEpisodesApiUrl($animeId, $pageSize);
        }

        return $this->buildAllEpisodesApiUrl($pageSize);
    }

    private function buildEpisodesApiUrl(int $animeId, int $pageSize): string
    {
        $params = [
            'filter[mediaType]' => 'Anime',
            'filter[media_id]' => $animeId,
            'sort' => '-airdate',
            'include' => 'media',
            'page[limit]' => $pageSize,
        ];

        return self::URI . '/api/edge/episodes?' . http_build_query($params);
    }

    private function buildAllEpisodesApiUrl(int $pageSize): string
    {
        $params = [
            'filter[mediaType]' => 'Anime',
            'sort' => '-airdate',
            'include' => 'media',
            'page[limit]' => $pageSize,
        ];

        return self::URI . '/api/edge/episodes?' . http_build_query($params);
    }

    private function findAnimeId(?string $name, ?string $urlPath): int
    {
        if ($urlPath !== null && $urlPath !== '') {
            $params = ['filter[slug]' => $urlPath];
        } else {
            $params = ['filter[text]' => $name];
        }

        $urlApiAnime = self::URI . '/api/edge/anime?' . http_build_query($params);
        $animeList = json_decode(getContents($urlApiAnime), true);

        if (is_array($animeList) === false || isset($animeList['meta']['count']) === false) {
            throwClientException('Invalid API response');
        }

        if ($animeList['meta']['count'] === 0 || isset($animeList['data'][0]['id']) === false) {
            throwClientException('Show not found');
        }

        return (int)$animeList['data'][0]['id'];
    }

    private function buildAnimeList(array $feedContent): array
    {
        $animeList = [];

        if (isset($feedContent['included']) === false || is_array($feedContent['included']) === false) {
            return $animeList;
        }

        foreach ($feedContent['included'] as $included) {
            if (is_array($included) === false) {
                continue;
            }

            if (($included['type'] ?? '') === 'anime') {
                $id = (int)($included['id'] ?? 0);
                $animeList[$id] = $included['attributes'] ?? [];
            }
        }

        return $animeList;
    }

    private function buildItem(array $episode, array $animeList): ?array
    {
        $animeId = (int)($episode['relationships']['media']['data']['id'] ?? 0);

        if ($animeId === 0 || isset($animeList[$animeId]) === false) {
            return null;
        }

        $anime = $animeList[$animeId];
        $canonicalTitle = (string)($anime['canonicalTitle'] ?? '');
        $slug = (string)($anime['slug'] ?? '');

        if ($canonicalTitle === '' || $slug === '') {
            return null;
        }

        $episodeNumber = (int)($episode['attributes']['number'] ?? 0);
        $episodeTitle = (string)($episode['attributes']['canonicalTitle'] ?? '');
        $description = (string)($episode['attributes']['description'] ?? '');
        $airdate = (string)($episode['attributes']['airdate'] ?? '');
        $createdAt = (string)($episode['attributes']['createdAt'] ?? '');
        $thumbnail = $episode['attributes']['thumbnail'] ?? null;

        $item = [];
        $item['title'] = e($canonicalTitle) . ': Episode ' . $episodeNumber;
        $item['uri'] = self::URI . '/anime/' . rawurlencode($slug) . '/episodes/' . $episodeNumber;
        $item['uid'] = (string)($episode['id'] ?? '');

        $content = '';

        if (is_array($thumbnail) === true && isset($thumbnail['original']) === true) {
            $thumbnailUrl = (string)$thumbnail['original'];
            if ($thumbnailUrl !== '') {
                $content .= '<img src="' . e($thumbnailUrl) . '" style="' . self::CSS['image'] . '" />';
            }
        }

        if ($episodeTitle !== '') {
            $content .= '<p><strong>' . e($episodeTitle) . '</strong></p>';
        }

        if ($description !== '') {
            $content .= '<p>' . e($description) . '</p>';
        }

        if ($airdate !== '') {
            $content .= '<p><em>Airdate: ' . e($airdate) . '</em></p>';
        }

        $item['content'] = $content;

        if ($createdAt !== '') {
            $timestamp = strtotime($createdAt);
            if ($timestamp !== false) {
                $item['timestamp'] = $timestamp;
            }
        }

        return $item;
    }
}
