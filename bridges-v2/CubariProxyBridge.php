<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

final class CubariProxyBridge extends BridgeAbstract
{
    public const NAME = 'Cubari Proxy';
    public const URI = 'https://cubari.moe';
    public const DESCRIPTION = 'Returns chapters from Cubari.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'service' => [
                'name' => 'Content service',
                'type' => 'list',
                'defaultValue' => 'mangadex',
                'values' => [
                    'GitHub Gist' => 'gist',
                    'Guya' => 'guya',
                    'Image Chest' => 'imgchest',
                    'Imgur' => 'imgur',
                    'MangaDventure' => 'mangadventure',
                    'MangaDex' => 'mangadex',
                    'MangaKatana' => 'mangakatana',
                    'NHentai' => 'nhentai',
                    'WeebCentral' => 'weebcentral',
                ]
            ],
            'series' => [
                'name' => 'Series ID/Name',
                'type' => 'text',
                'exampleValue' => '8c1d7d0c-e0b7-4170-941d-29f652c3c19d',
                'required' => true,
            ],
            'fetch' => [
                'name' => 'Fetch chapter page images',
                'type' => 'list',
                'title' => 'Places chapter images in feed contents. Entries will consume more bandwidth.',
                'defaultValue' => 'c',
                'values' => [
                    'None' => 'n',
                    'Content' => 'c',
                    'Enclosure' => 'e'
                ]
            ],
            'limit' => [
                'name' => 'Item limit',
                'type' => 'number',
                'defaultValue' => 10,
            ]
        ]
    ];

    private ?string $title = null;

    public function collectData(): void
    {
        $service = (string) $this->getInput('service');
        $series = (string) $this->getInput('series');
        $fetchMode = (string) $this->getInput('fetch');
        $limitInput = $this->getInput('limit');
        $limit = is_numeric($limitInput) === true ? (int) $limitInput : 10;

        $apiUrl = self::URI . '/read/api/' . $service . '/series/' . $series;

        $json = [];
        try {
            $jsonRaw = getContents($apiUrl);
            $decoded = Json::decode($jsonRaw);
            if (is_array($decoded) === true) {
                $json = $decoded;
            }
        } catch (\Throwable $e) {
            throwServerException('Failed to fetch series data: ' . $e->getMessage());
        }

        if (isset($json['title']) === false || is_array($json['chapters']) === false) {
            throwServerException('Invalid API response: missing title or chapters.');
        }

        $this->title = (string) $json['title'];
        $chapters = $json['chapters'];

        krsort($chapters);

        $count = 0;
        foreach ($chapters as $number => $element) {
            if ($count >= $limit) {
                break;
            }

            $item = [];
            $item['uri'] = self::URI . '/read/' . $service . '/' . $series . '/' . $number;

            if (isset($element['title']) === true && (string) $element['title'] !== '') {
                $item['title'] = $number . ' - ' . $element['title'];
            } else {
                $volume = isset($element['volume']) === true ? (string) $element['volume'] : '?';
                $item['title'] = 'Volume ' . $volume . ' Chapter ' . $number;
            }

            $group = '1';
            if (isset($element['release_date']) === true && is_array($element['release_date']) === true && $element['release_date'] !== []) {
                $dates = $element['release_date'];
                $date = max($dates);
                $item['timestamp'] = (int) $date;
                $keys = array_keys($dates, $date);
                if (isset($keys[0]) === true) {
                    $group = (string) $keys[0];
                }
            }

            $pages = [];
            if (isset($element['groups'][$group]) === true) {
                $pages = $element['groups'][$group];
            }

            if (isset($json['groups'][$group]) === true) {
                $item['author'] = (string) $json['groups'][$group];
            }

            if (is_array($pages) === true) {
                $item['uid'] = implode(',', $pages);
            } else {
                $item['uid'] = (string) $pages;
            }

            if (isset($element['folder']) === true) {
                $folder = (string) $element['folder'];
                $prefix = self::URI . '/media/manga/' . $series . '/chapters/' . $folder . '/' . $group . '/';
                if (is_array($pages) === true) {
                    $pages = array_map(function ($page) use ($prefix) {
                        return $prefix . (string) $page;
                    }, $pages);
                }
                $item['uid'] = $prefix;
            }

            if ($fetchMode !== 'n') {
                if (is_array($pages) === false && is_string($pages) === true) {
                    $api = self::URI . $pages;
                    $item['comments'] = $api;
                    try {
                        $jsonp = getContents($api);
                        $pages = Json::decode($jsonp);
                    } catch (\Throwable $e) {
                        if ($e->getCode() !== 500) {
                            throwServerException('Failed to fetch chapter pages: ' . $e->getMessage());
                        }
                        $pages = [];
                    }
                }

                if (is_array($pages) === false) {
                    $pages = [];
                }

                if ($fetchMode === 'e') {
                    $item['enclosures'] = $pages;
                } elseif ($fetchMode === 'c') {
                    $content = '';
                    foreach ($pages as $img) {
                        $imgSrc = '';
                        if (is_array($img) === true && isset($img['src']) === true) {
                            $imgSrc = (string) $img['src'];
                        } elseif (is_string($img) === true) {
                            $imgSrc = $img;
                        }

                        if ($imgSrc !== '') {
                            $content .= '<img src="' . htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') . '" />';
                        }
                    }
                    $item['content'] = $content;
                }
            }

            $this->items[] = $item;
            $count++;
        }
    }

    public function getName(): string
    {
        $name = parent::getName();
        if ($this->title !== null) {
            $name .= ' - ' . $this->title;
        }
        return $name;
    }

    public function getURI(): string
    {
        $service = $this->getInput('service');
        $series = $this->getInput('series');

        if (is_string($service) === true && $service !== '' && is_string($series) === true && $series !== '') {
            return self::URI . '/read/' . $service . '/' . $series;
        }

        return parent::getURI();
    }

    public function getIcon(): string
    {
        return self::URI . '/static/favicon.png';
    }
}
