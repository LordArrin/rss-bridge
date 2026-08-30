<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class BlizzardNewsBridge extends BridgeAbstract
{
    public const NAME = 'Blizzard News';
    public const URI = 'https://news.blizzard.com';
    public const DESCRIPTION = 'Blizzard newsfeed';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'locale' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => [
                    'Deutsch' => 'de-de',
                    'English (EU)' => 'en-gb',
                    'English (US)' => 'en-us',
                    'Español (EU)' => 'es-es',
                    'Español (AL)' => 'es-mx',
                    'Français' => 'fr-fr',
                    'Italiano' => 'it-it',
                    '日本語' => 'ja-jp',
                    '한국어' => 'ko-kr',
                    'Polski' => 'pl-pl',
                    'Português (AL)' => 'pt-br',
                    'Русский' => 'ru-ru',
                    'ภาษาไทย' => 'th-th',
                    '繁體中文' => 'zh-tw',
                ],
                'defaultValue' => 'en-us',
                'title' => 'Select your language',
            ],
        ],
    ];

    private const PRODUCT_IDS = [
        'blt525c436e4a1b0a97',
        'blt54fbd3787a705054',
        'blt2031aef34200656d',
        'blt795c314400d7ded9',
        'blt5cfc6affa3ca0638',
        'blt2e50e1521bb84dc6',
        'blt376fb94931906b6f',
        'blt81d46fcb05ab8811',
        'bltede2389c0a8885aa',
        'blt24859ba8086fb294',
        'blte27d02816a8ff3e1',
        'blt2caca37e42f19839',
        'blt90855744d00cd378',
        'bltec70ad0ea4fd6d1d',
        'blt500c1f8b5470bfdb',
    ];

    private const API_PATH = '/api/news/blizzard?';

    public function collectData()
    {
        $feedContent = json_decode(getContents($this->getSourceUrl()), true);

        if (is_array($feedContent) === false || isset($feedContent['feed']['contentItems']) === false) {
            throwServerException('Invalid response from Blizzard API');
        }

        if (is_array($feedContent['feed']['contentItems']) === false) {
            throwServerException('Invalid feed structure');
        }

        foreach ($feedContent['feed']['contentItems'] as $entry) {
            if (is_array($entry) === false || isset($entry['properties']) === false) {
                continue;
            }

            $properties = $entry['properties'];

            if (is_array($properties) === false) {
                continue;
            }

            $title = '';
            if (isset($properties['title']) === true) {
                $title = (string) $properties['title'];
            }

            $summary = '';
            if (isset($properties['summary']) === true) {
                $summary = (string) $properties['summary'];
            }

            $newsUrl = '';
            if (isset($properties['newsUrl']) === true) {
                $newsUrl = (string) $properties['newsUrl'];
            }

            $author = null;
            if (isset($properties['author']) === true) {
                $author = (string) $properties['author'];
            }

            $lastUpdated = '';
            if (isset($properties['lastUpdated']) === true) {
                $lastUpdated = (string) $properties['lastUpdated'];
            }

            $imageUrl = $properties['staticAsset']['imageUrl'] ?? null;
            $productTitle = $properties['cxpProduct']['title'] ?? null;

            if ($title === '' || $newsUrl === '') {
                continue;
            }

            $item = [];
            $item['title'] = e($title);
            $item['content'] = e($summary);
            $item['uri'] = $newsUrl;
            $item['author'] = $author !== null ? e($author) : null;

            if ($lastUpdated !== '') {
                $timestamp = strtotime($lastUpdated);
                if ($timestamp !== false) {
                    $item['timestamp'] = $timestamp;
                }
            }

            if (is_string($imageUrl) === true && $imageUrl !== '') {
                $item['enclosures'] = [$imageUrl];
            }

            if (is_string($productTitle) === true && $productTitle !== '') {
                $item['categories'] = [e($productTitle)];
            }

            $this->items[] = $item;
        }
    }

    private function getSourceUrl(): string
    {
        $locale = (string)$this->getInput('locale');
        $baseUrl = self::URI . '/' . $locale . self::API_PATH;

        $queryParams = array_map(
            fn(string $id): string => 'feedCxpProductIds[]=' . rawurlencode($id),
            self::PRODUCT_IDS
        );

        return $baseUrl . implode('&', $queryParams);
    }
}
