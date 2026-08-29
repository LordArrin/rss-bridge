<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class MinecraftBridge extends BridgeAbstract
{
    public const NAME = 'Minecraft';
    public const URI = 'https://www.minecraft.net';
    public const DESCRIPTION = 'Catch up on the latest Minecraft articles';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            'category' => [
                'type' => 'list',
                'name' => 'Category',
                'values' => [
                    'All' => 'all',
                    'Deep Dives' => 'minecraft:news/deep-dives',
                    'News' => 'minecraft:news',
                    'Marketplace' => 'minecraft:marketplace',
                ],
                'title' => 'Choose article category',
                'defaultValue' => 'all',
            ]
        ]
    ];

    public function collectData(): void
    {
        $url = 'https://net-secondary.web.minecraft-services.net/api/v1.0/en-us/search?sortType=Recent&category=News&newsOnly=false';
        $raw = getContents($url);

        if (is_string($raw) === false || $raw === '') {
            \throwServerException('Empty response from Minecraft API');
        }

        $data = json_decode($raw, false);

        if (is_object($data) === false) {
            \throwServerException('Invalid JSON response from Minecraft API');
        }

        $results = $data->result->results ?? null;
        if (is_array($results) === false) {
            \throwServerException('Invalid or empty content');
        }

        $categoryInput = $this->getInput('category');
        if (is_string($categoryInput) === true && $categoryInput !== '') {
            $category = $categoryInput;
        } else {
            $category = 'all';
        }

        foreach ($results as $article) {
            if (is_object($article) === false) {
                continue;
            }

            $tags = $article->tags ?? [];
            if (is_array($tags) === false) {
                $tags = [];
            }

            if ($category !== 'all' && in_array($category, $tags, true) === false) {
                continue;
            }

            $imageUrl = (string) ($article->image ?? '');

            $normalizedTags = array_filter($tags, function ($value): bool {
                return $value !== 'article-page';
            });
            $normalizedTags = array_map([$this, 'normalizeTag'], $normalizedTags);
            $normalizedTags = array_values($normalizedTags);

            $articleUrl = (string) ($article->url ?? '');
            $uid = '';
            if ($articleUrl !== '') {
                $path = parse_url($articleUrl, PHP_URL_PATH);
                if (is_string($path) === true) {
                    $uid = $path;
                }
            }

            $timeValue = $article->time ?? null;
            $timestamp = null;
            if (is_numeric($timeValue) === true) {
                $timestamp = (int) $timeValue;
            } elseif (is_string($timeValue) === true && $timeValue !== '') {
                $ts = strtotime($timeValue);
                if ($ts !== false) {
                    $timestamp = $ts;
                }
            }

            $description = (string) ($article->description ?? '');

            $content = '';
            if ($imageUrl !== '') {
                $content .= '<p><img src="' . htmlspecialchars($imageUrl) . '" style="' . self::CSS['img'] . '" alt="" /></p>';
            }
            if ($description !== '') {
                $content .= '<p>' . htmlspecialchars($description) . '</p>';
            }

            $item = [
                'title' => trim((string) ($article->title ?? '')),
                'uid' => $uid,
                'uri' => $articleUrl,
                'author' => (string) ($article->author ?? ''),
                'content' => $content,
                'categories' => $normalizedTags,
            ];

            if ($timestamp !== null && $timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }

    private function normalizeTag(mixed $tag): string
    {
        if (is_string($tag) === false) {
            return '';
        }

        $index = strpos($tag, '/');
        if ($index !== false) {
            $tag = substr($tag, $index + 1);
            if ($tag === false) {
                $tag = '';
            }
        }

        $tag = str_replace('-', ' ', $tag);
        return ucwords($tag);
    }
}
