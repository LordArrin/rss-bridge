<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class GOGBridge extends BridgeAbstract
{
    public const NAME = 'GOG';
    public const URI = 'https://gog.com';
    public const DESCRIPTION = 'Returns the latest releases from GOG';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const CATALOG_URL = 'https://catalog.gog.com/v1/catalog?limit=48&order=desc%3AstoreReleaseDate';
    private const PRODUCT_URL = 'https://api.gog.com/products/%s?expand=description';
    private const ITEM_LIMIT = 10;

    public function collectData(): void
    {
        $catalogResponse = getContents(self::CATALOG_URL);

        if ($catalogResponse === '') {
            throwServerException('Failed to fetch catalog from GOG API');
        }

        $catalog = json_decode($catalogResponse, false);

        if (is_object($catalog) === false || isset($catalog->products) === false || is_array($catalog->products) === false) {
            throwServerException('Failed to parse GOG catalog response');
        }

        $count = 0;
        foreach ($catalog->products as $game) {
            if (is_object($game) === false) {
                continue;
            }

            $id = $this->extractInt($game, 'id');
            if ($id === 0) {
                continue;
            }

            $storeLink = $this->extractString($game, 'storeLink');
            $title = $this->extractString($game, 'title');

            if ($storeLink === '' || $title === '') {
                continue;
            }

            $developers = $this->extractStringArray($game, 'developers');
            $publishers = $this->extractStringArray($game, 'publishers');

            $authorParts = [];
            if ($developers !== []) {
                $authorParts[] = implode(', ', $developers);
            }
            if ($publishers !== []) {
                $authorParts[] = implode(', ', $publishers);
            }
            $author = implode(' / ', $authorParts);

            $timestamp = $this->extractTimestamp($game);

            $content = $this->buildGameContent($game);

            $this->items[] = [
                'title' => $title,
                'uri' => $storeLink,
                'author' => $author,
                'timestamp' => $timestamp,
                'content' => $content,
                'uid' => (string) $id,
            ];

            $count++;
            if ($count >= self::ITEM_LIMIT) {
                break;
            }
        }

        if ($this->items === []) {
            throwServerException('No games could be extracted from GOG catalog');
        }
    }

    private function buildGameContent(object $game): string
    {
        $id = $this->extractInt($game, 'id');
        $productUrl = sprintf(self::PRODUCT_URL, $id);
        $descriptionResponse = getContents($productUrl);

        $fullDescription = '';
        if ($descriptionResponse !== '') {
            $productData = json_decode($descriptionResponse, false);
            if (
                is_object($productData) === true
                && isset($productData->description->full) === true
                && is_string($productData->description->full) === true
            ) {
                $fullDescription = $productData->description->full;
            }
        }

        $genres = $this->extractGenreNames($game);
        $platforms = $this->extractStringArray($game, 'operatingSystems');

        $html = '<p>';
        $html .= '<strong>Genres:</strong> ' . htmlspecialchars(implode(', ', $genres), ENT_QUOTES, 'UTF-8');
        $html .= '<br><strong>Supported Platforms:</strong> ' . htmlspecialchars(implode(', ', $platforms), ENT_QUOTES, 'UTF-8');
        $html .= '</p>';

        if ($fullDescription !== '') {
            $html .= $fullDescription;
        }

        return $html;
    }

    private function extractGenreNames(object $game): array
    {
        $genres = $game->genres ?? null;
        if (is_array($genres) === false) {
            return [];
        }

        $names = [];
        foreach ($genres as $genre) {
            if (is_object($genre) === true && isset($genre->name) === true && is_string($genre->name) === true) {
                $names[] = $genre->name;
            }
        }

        return $names;
    }

    private function extractTimestamp(object $game): int
    {
        $dateFields = ['storeReleaseDate', 'releaseDate', 'globalReleaseDate'];

        foreach ($dateFields as $field) {
            if (isset($game->{$field}) === true && is_string($game->{$field}) === true && $game->{$field} !== '') {
                $parsed = strtotime($game->{$field});
                if ($parsed !== false && $parsed <= time()) {
                    return $parsed;
                }
            }
        }

        return time();
    }

    private function extractInt(object $obj, string $property): int
    {
        if (isset($obj->{$property}) === false) {
            return 0;
        }

        $value = $obj->{$property};

        if (is_int($value) === true) {
            return $value;
        }

        if (is_string($value) === true && is_numeric($value) === true) {
            return (int) $value;
        }

        return 0;
    }

    private function extractString(object $obj, string $property): string
    {
        if (isset($obj->{$property}) === false) {
            return '';
        }

        $value = $obj->{$property};

        if (is_string($value) === true) {
            return $value;
        }

        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }

        return '';
    }

    private function extractStringArray(object $obj, string $property): array
    {
        if (isset($obj->{$property}) === false) {
            return [];
        }

        $value = $obj->{$property};

        if (is_array($value) === false) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item) === true && $item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }
}
