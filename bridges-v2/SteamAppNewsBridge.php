<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class SteamAppNewsBridge extends BridgeAbstract
{
    public const NAME = 'Steam App News';
    public const URI = 'https://www.steamcommunity.com';
    public const DESCRIPTION = 'Get the latest news for a game on Steam.';
    public const MAINTAINER = 'otakuf';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'appid' => [
                'name' => 'App ID',
                'title' => 'App ID (only digits). Find your App ID with steamdb.info',
                'type' => 'number',
                'exampleValue' => '730',
                'required' => true
            ],
            'maxlength' => [
                'name' => 'Max Length',
                'title' => 'Maximum length for the content to return, 0 for full content',
                'type' => 'number',
                'defaultValue' => 0
            ],
            'count' => [
                'name' => 'Count',
                'title' => '# of posts to retrieve (default 20)',
                'type' => 'number',
                'defaultValue' => 20
            ],
            'tags' => [
                'name' => 'Tag Filter',
                'title' => 'Comma-separated list of tags to filter by',
                'type' => 'text',
                'exampleValue' => 'patchnotes'
            ]
        ]
    ];

    public function collectData(): void
    {
        $apiTarget = 'https://api.steampowered.com/ISteamNews/GetNewsForApp/v2/';

        $appid = (string) ($this->getInput('appid') ?? '');
        $maxlength = (string) ($this->getInput('maxlength') ?? '0');
        $count = (string) ($this->getInput('count') ?? '20');
        $tags = (string) ($this->getInput('tags') ?? '');

        $url = $apiTarget
            . '?appid=' . $appid
            . '&maxlength=' . $maxlength
            . '&count=' . $count
            . '&tags=' . $tags;

        $json = getContents($url);
        $json_list = json_decode($json, true);

        if (is_array($json_list) === false) {
            \throwServerException('Invalid API response format');
        }

        if (isset($json_list['appnews']['newsitems']) === false || is_array($json_list['appnews']['newsitems']) === false) {
            \throwServerException('News items not found in API response');
        }

        foreach ($json_list['appnews']['newsitems'] as $json_item) {
            if (is_array($json_item) === true) {
                $this->items[] = $this->collectArticle($json_item);
            }
        }
    }

    private function collectArticle(array $json_item): array
    {
        $item = [];

        $url = (string) ($json_item['url'] ?? '');
        $replacedUrl = preg_replace('[ ]', '%20', $url);
        $item['uri'] = (is_string($replacedUrl) === true) ? $replacedUrl : $url;

        $item['title'] = (string) ($json_item['title'] ?? '');

        $timestamp = $json_item['date'] ?? null;
        if (is_numeric($timestamp) === true) {
            $item['timestamp'] = (int) $timestamp;
        } else {
            $item['timestamp'] = time();
        }

        $item['author'] = (string) ($json_item['author'] ?? '');

        $contents = (string) ($json_item['contents'] ?? '');
        if (str_contains($item['uri'], 'steam_community_announcements') === true) {
            $item['content'] = $this->replaceBBcodes($contents);
        } else {
            $item['content'] = $contents;
        }

        $item['uid'] = (string) ($json_item['gid'] ?? '');
        return $item;
    }

    private function replaceBBcodes(string $text): string
    {
        $text = nl2br($text);

        $find = [
            '~\[h1\](.*?)\[/h1\]~s',
            '~\[h2\](.*?)\[/h2\]~s',
            '~\[h3\](.*?)\[/h3\]~s',
            '~\[p\](.*?)\[/p\]~s',
            '~\[list\](.*?)\[/list\]~s',
            '~\[olist\](.*?)\[/olist\]~s',
            '~\[\*\](.*?)\[/\*\]~s',
            '~\[\*\]~s',
            '~\[/\*\]~s',
            '~\[b\](.*?)\[/b\]~s',
            '~\[i\](.*?)\[/i\]~s',
            '~\[u\](.*?)\[/u\]~s',
            '~\[strike\](.*?)\[/strike\]~s',
            '~\[spoiler\](.*?)\[/spoiler\]~s',
            '~\[noparse\](.*?)\[/noparse\]~s',
            '~\[hr\]~s',
            '~\[quote\](.*?)\[/quote\]~s',
            '~\[code\](.*?)\[/code\]~s',
            '~\{STEAM_CLAN_IMAGE\}~s',
            '~\[url=([^"><]*?)\](.*?)\[/url\]~s',
            '~\[img\](https?://[^"><]*?\.(?:jpg|jpeg|gif|png|bmp))\[/img\]~s',
            '~\\\\\[(.*?)\\\\\]~s'
        ];

        $replace = [
            '<h1>$1</h1>',
            '<h2>$1</h2>',
            '<h3>$1</h3>',
            '<p>$1</p>',
            '<ul>$1</ul>',
            '<ol>$1</ol>',
            '<li>$1</li>',
            '<li>',
            '</li>',
            '<b>$1</b>',
            '<i>$1</i>',
            '<u>$1</u>',
            '<s>$1</s>',
            '$1',
            '$1',
            '<hr>',
            '<blockquote>$1</blockquote>',
            '<code>$1</code>',
            'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/clans',
            '<a href="$1">$2</a>',
            '<img src="$1" alt="" />',
            '[$1]'
        ];

        $result = preg_replace($find, $replace, $text);
        return (is_string($result) === true) ? $result : $text;
    }
}
