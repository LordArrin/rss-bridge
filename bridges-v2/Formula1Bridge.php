<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class Formula1Bridge extends BridgeAbstract
{
    public const NAME = 'Formula1';
    public const URI = 'https://formula1.com/';
    public const DESCRIPTION = 'Returns latest official Formula 1 news';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const API_KEY = 'xZ7AOODSjiQadLsIYWefQrpCSQVDbHGC';
    public const API_URL = 'https://api.formula1.com/v1/editorial/articles?limit=%u';

    public const ARTICLE_AUTHOR = 'Formula 1';
    public const ARTICLE_URL = 'https://formula1.com/en/latest/article/%s.%s';

    public const LIMIT_MIN = 1;
    public const LIMIT_DEFAULT = 10;
    public const LIMIT_MAX = 100;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => false,
                'title' => 'Number of articles to return',
                'exampleValue' => self::LIMIT_DEFAULT,
                'defaultValue' => self::LIMIT_DEFAULT
            ]
        ]
    ];

    public function collectData(): void
    {
        $limitInput = $this->getInput('limit');
        if (is_numeric($limitInput) === true) {
            $limit = (int) $limitInput;
        } else {
            $limit = self::LIMIT_DEFAULT;
        }
        $limit = min(self::LIMIT_MAX, max(self::LIMIT_MIN, $limit));

        $url = sprintf(self::API_URL, $limit);

        $headers = [
            'Accept: application/json',
            'apikey: ' . self::API_KEY,
            'locale: en'
        ];

        $raw = getContents($url, $headers);

        if (is_string($raw) === false || $raw === '') {
            \throwServerException('Empty response from Formula 1 API');
        }

        $json = json_decode($raw, false);

        if (is_object($json) === false) {
            \throwServerException('Invalid JSON response from Formula 1 API');
        }

        if (property_exists($json, 'error') === true) {
            $errorMessage = (string) ($json->message ?? 'Unknown error');
            \throwServerException($errorMessage);
        }

        $list = $json->items ?? null;
        if (is_array($list) === false) {
            \throwServerException('No items found in API response');
        }

        foreach ($list as $article) {
            if (is_object($article) === false) {
                continue;
            }

            $caption = '';
            $thumbnail = $article->thumbnail ?? null;
            if (is_object($thumbnail) === true) {
                if (property_exists($thumbnail, 'caption') === true) {
                    $caption = (string) ($thumbnail->caption ?? '');
                } else {
                    $image = $thumbnail->image ?? null;
                    if (is_object($image) === true && property_exists($image, 'title') === true) {
                        $caption = (string) ($image->title ?? '');
                    }
                }
            }

            $slug = (string) ($article->slug ?? '');
            $articleId = (string) ($article->id ?? '');

            if ($slug === '' || $articleId === '') {
                continue;
            }

            $uri = sprintf(self::ARTICLE_URL, $slug, $articleId);
            $title = (string) ($article->title ?? '');

            $updatedAt = (string) ($article->updatedAt ?? '');
            $timestamp = null;
            if ($updatedAt !== '') {
                $ts = strtotime($updatedAt);
                if ($ts !== false) {
                    $timestamp = $ts;
                }
            }

            $metaDescription = (string) ($article->metaDescription ?? '');
            $descriptionText = ($metaDescription !== '') ? $metaDescription : $title;

            $imageUrl = '';
            if (is_object($thumbnail) === true) {
                $image = $thumbnail->image ?? null;
                if (is_object($image) === true) {
                    $imageUrl = (string) ($image->url ?? '');
                }
            }

            $content = '<p>' . htmlspecialchars($descriptionText) . '</p>';

            if ($imageUrl !== '') {
                $content .= '<a href="' . htmlspecialchars($uri) . '" target="_blank">';
                $content .= '<img src="' . htmlspecialchars($imageUrl) . '" style="' . self::CSS['img'] . '" ';
                $content .= 'alt="' . htmlspecialchars($caption) . '" title="' . htmlspecialchars($caption) . '" />';
                $content .= '</a>';
            }

            $item = [
                'uri' => $uri,
                'title' => $title,
                'author' => self::ARTICLE_AUTHOR,
                'uid' => $articleId,
                'content' => $content,
            ];

            if ($timestamp !== null && $timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }
}
