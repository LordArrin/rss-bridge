<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class RainbowSixSiegeBridge extends BridgeAbstract
{
    public const NAME = 'Rainbow Six Siege News';
    public const URI = 'https://www.ubisoft.com/en-us/game/rainbow-six/siege/news-updates';
    public const DESCRIPTION = 'Latest news about Rainbow Six Siege';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 7200;

    public const PARAMETERS = [];

    public const NIMBUS_API_KEY = '3b5a8be6dde511ec9d640242ac120002';

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public function collectData(): void
    {
        $dlUrl = 'https://nimbus.ubisoft.com/api/v1/items?categoriesFilter=all';
        $dlUrl .= '&limit=6&mediaFilter=all&skip=0&startIndex=0&tags=BR-rainbow-six%20GA-siege';
        $dlUrl .= '&locale=en-us&fallbackLocale=en-us&environment=master';

        $jsonString = getContents($dlUrl, [
            'Authorization: ' . self::NIMBUS_API_KEY,
        ]);

        if (is_string($jsonString) === false || $jsonString === '') {
            \throwServerException('Empty response from Ubisoft API');
        }

        $json = \Json::decode($jsonString);

        if (is_array($json) === false) {
            \throwServerException('Invalid JSON response from Ubisoft API');
        }

        $items = $json['items'] ?? null;
        if (is_array($items) === false) {
            \throwServerException('No items found in API response');
        }

        foreach ($items as $jsonItem) {
            if (is_array($jsonItem) === false) {
                continue;
            }

            $buttonUrl = (string) ($jsonItem['button']['buttonUrl'] ?? '');
            $thumbnailUrl = (string) ($jsonItem['thumbnail']['url'] ?? '');
            $contentRaw = (string) ($jsonItem['content'] ?? '');
            $dateStr = (string) ($jsonItem['date'] ?? '');
            $articleId = (string) ($jsonItem['id'] ?? '');
            $title = (string) ($jsonItem['title'] ?? '');

            if ($title === '' || $articleId === '') {
                continue;
            }

            $uri = 'https://www.ubisoft.com/en-us/game/rainbow-six/siege/news-updates';
            if ($buttonUrl !== '') {
                $uri .= $buttonUrl;
            }

            $content = '';

            if ($thumbnailUrl !== '') {
                $content .= '<img src="' . htmlspecialchars($thumbnailUrl) . '" alt="Thumbnail" style="' . self::CSS['img'] . '" />';
            }

            if ($content !== '') {
                $content .= '<br />';
            }

            if ($contentRaw !== '') {
                if (function_exists('markdownToHtml') === true) {
                    $content .= \markdownToHtml($contentRaw);
                } else {
                    $content .= nl2br(htmlspecialchars($contentRaw));
                }
            }

            $timestamp = null;
            if ($dateStr !== '') {
                $cleanDate = str_replace('(Coordinated Universal Time)', '', $dateStr);
                $ts = strtotime($cleanDate);
                if ($ts !== false) {
                    $timestamp = $ts;
                }
            }

            $item = [
                'uri' => $uri,
                'uid' => $articleId,
                'title' => $title,
                'content' => $content,
            ];

            if ($timestamp !== null && $timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }
}
