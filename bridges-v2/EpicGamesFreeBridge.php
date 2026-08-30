<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class EpicGamesFreeBridge extends BridgeAbstract
{
    public const NAME = 'Epic Games Free Games';
    public const URI = 'https://store.epicgames.com/';
    public const DESCRIPTION = 'Returns the latest free games from Epic Games';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            'locale' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => [
                    'English' => 'en-US',
                    'العربية' => 'ar',
                    'Deutsch' => 'de',
                    'Español (Spain)' => 'es-ES',
                    'Español (LA)' => 'es-MX',
                    'Français' => 'fr',
                    'Italiano' => 'it',
                    '日本語' => 'ja',
                    '한국어' => 'ko',
                    'Polski' => 'pl',
                    'Português (Brasil)' => 'pt-BR',
                    'Русский' => 'ru',
                    'ไทย' => 'th',
                    'Türkçe' => 'tr',
                    '简体中文' => 'zh-CN',
                    '繁體中文' => 'zh-Hant',
                ],
                'title' => 'Language for game information',
                'defaultValue' => 'en-US',
            ],
            'country' => [
                'name' => 'Country',
                'title' => 'Country store to check for deals',
                'defaultValue' => 'US',
            ]
        ]
    ];

    public function collectData(): void
    {
        $url = 'https://store-site-backend-static.ak.epicgames.com/freeGamesPromotions?';

        $localeInput = $this->getInput('locale');
        if (is_string($localeInput) === true && $localeInput !== '') {
            $locale = $localeInput;
        } else {
            $locale = 'en-US';
        }

        $countryInput = $this->getInput('country');
        if (is_string($countryInput) === true && $countryInput !== '') {
            $country = $countryInput;
        } else {
            $country = 'US';
        }

        $params = [
            'locale' => $locale,
            'country' => $country,
            'allowCountries' => $country,
        ];
        $url .= http_build_query($params);

        $raw = getContents($url);
        if (is_string($raw) === false || $raw === '') {
            \throwServerException('Empty response from Epic Games API');
        }

        $json = \Json::decode($raw);

        if (is_array($json) === false) {
            \throwServerException('Invalid JSON response from Epic Games API');
        }

        $elements = $json['data']['Catalog']['searchStore']['elements'] ?? null;
        if (is_array($elements) === false) {
            return;
        }

        foreach ($elements as $element) {
            if (is_array($element) === false) {
                continue;
            }

            $promo = $element['promotions']['promotionalOffers'][0]['promotionalOffers'][0] ?? null;
            if (is_array($promo) === false) {
                continue;
            }

            $discountType = (string) ($promo['discountSetting']['discountType'] ?? '');
            $discountPercentage = (int) ($promo['discountSetting']['discountPercentage'] ?? -1);

            if ($discountType !== 'PERCENTAGE' || $discountPercentage !== 0) {
                continue;
            }

            $mappings = $element['catalogNs']['mappings'] ?? [];
            if (is_array($mappings) === false) {
                $mappings = [];
            }

            $slug = null;
            if (count($mappings) > 0 && is_array($mappings[0]) === true) {
                $slug = $mappings[0]['pageSlug'] ?? null;
            }

            if (is_string($slug) === true && $slug !== '') {
                $uri = parent::getURI() . $locale . '/p/' . $slug;
            } else {
                $uri = parent::getURI() . $locale . '/free-games';
            }

            $keyImages = $element['keyImages'] ?? [];
            if (is_array($keyImages) === false) {
                $keyImages = [];
            }

            $content = (string) ($element['description'] ?? '');

            $seenImages = [];
            foreach ($keyImages as $imageItem) {
                if (is_array($imageItem) === false) {
                    continue;
                }
                $imageUrl = (string) ($imageItem['url'] ?? '');
                if ($imageUrl === '') {
                    continue;
                }

                $imageUrl = $this->cleanImageUrl($imageUrl);

                if (in_array($imageUrl, $seenImages, true) === true) {
                    continue;
                }
                $seenImages[] = $imageUrl;

                $content .= '<p><img src="' . htmlspecialchars($imageUrl) . '" style="' . self::CSS['img'] . '" alt="" /></p>';
            }

            $startDate = (string) ($promo['startDate'] ?? '');
            $timestamp = ($startDate !== '') ? strtotime($startDate) : false;

            $item = [
                'author' => (string) ($element['seller']['name'] ?? 'Epic Games'),
                'content' => $content,
                'title' => (string) ($element['title'] ?? ''),
                'uri' => $uri,
                'uid' => $uri,
            ];

            if ($timestamp !== false && $timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }

    private function cleanImageUrl(string $url): string
    {
        $fragmentPos = strpos($url, '#');
        if ($fragmentPos !== false) {
            $url = substr($url, 0, $fragmentPos);
        }
        return $url;
    }

    public function getURI(): string
    {
        $localeInput = $this->getInput('locale');
        if (is_string($localeInput) === true && $localeInput !== '') {
            $locale = $localeInput;
        } else {
            $locale = 'en-US';
        }

        return parent::getURI() . $locale . '/free-games';
    }
}
