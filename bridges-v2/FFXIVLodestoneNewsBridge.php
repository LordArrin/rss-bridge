<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class FFXIVLodestoneNewsBridge extends BridgeAbstract
{
    public const NAME = 'FFXIV Lodestone News';
    public const URI = 'https://eu.finalfantasyxiv.com/lodestone/';
    public const DESCRIPTION = 'Catch up on the latest FFXIV Lodestone articles';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            'region' => [
                'type' => 'list',
                'name' => 'Region',
                'values' => [
                    'North America' => 'na',
                    'Europe' => 'eu',
                    'France' => 'fr',
                    'Germany' => 'de',
                    'Japan' => 'jp',
                ],
                'title' => 'Choose region',
                'defaultValue' => 'eu',
            ],
            'category' => [
                'type' => 'list',
                'name' => 'Category',
                'values' => [
                    'All' => 'feed',
                    'Topics' => 'topics',
                    'Notices' => 'notices',
                    'Maintenance' => 'maintenance',
                    'Updates' => 'updates',
                    'Status' => 'status',
                    'Developers\' Blog' => 'developers',
                ],
                'title' => 'Choose article category',
                'defaultValue' => 'feed',
            ]
        ]
    ];

    public function collectData(): void
    {
        $regionInput = $this->getInput('region');
        if (is_string($regionInput) === true && $regionInput !== '') {
            $region = $regionInput;
        } else {
            $region = 'eu';
        }

        $categoryInput = $this->getInput('category');
        if (is_string($categoryInput) === true && $categoryInput !== '') {
            $category = $categoryInput;
        } else {
            $category = 'feed';
        }

        $url = 'https://lodestonenews.com/news/' . $category . '?locale=' . $region;
        $raw = getContents($url);

        if (is_string($raw) === false || $raw === '') {
            \throwServerException('Empty response from Lodestone News API');
        }

        $articles = json_decode($raw, false);

        if (is_array($articles) === false) {
            \throwServerException('Failed to decode JSON content');
        }

        foreach ($articles as $article) {
            if (is_object($article) === false) {
                continue;
            }

            $articleUrl = (string) ($article->url ?? '');
            $articleId = (string) ($article->id ?? '');

            if ($articleUrl === '') {
                continue;
            }

            $title = (string) ($article->title ?? '');

            $timeValue = $article->time ?? null;
            $timestamp = null;
            if (is_numeric($timeValue) === true) {
                $ts = (int) $timeValue;
                if ($ts > 9999999999) {
                    $ts = (int) ($ts / 1000);
                }
                $timestamp = $ts;
            } elseif (is_string($timeValue) === true && $timeValue !== '') {
                $ts = strtotime($timeValue);
                if ($ts !== false) {
                    $timestamp = $ts;
                }
            }

            $description = (string) ($article->description ?? '');
            $imageUrl = (string) ($article->image ?? '');
            $articleCategory = (string) ($article->category ?? '');

            $categories = [];
            if ($articleCategory !== '') {
                $categories[] = ucfirst($articleCategory);
            } else {
                $categories[] = ucfirst($category);
            }

            $content = '';
            if ($description !== '') {
                $content .= '<p>' . htmlspecialchars($description) . '</p>';
            }

            if ($imageUrl !== '') {
                $content .= '<a href="' . htmlspecialchars($articleUrl) . '" target="_blank">';
                $content .= '<img src="' . htmlspecialchars($imageUrl) . '" style="' . self::CSS['img'] . '" alt="" />';
                $content .= '</a>';
            }

            $item = [
                'uri' => $articleUrl,
                'title' => $title,
                'content' => $content,
                'categories' => $categories,
                'uid' => ($articleId !== '') ? $articleId : $articleUrl,
            ];

            if ($timestamp !== null && $timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }
}
