<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function RSSBridge\Exceptions\throwServerException;

final class SteamAppNewsBridge extends BridgeAbstract
{
    public const NAME = 'Steam App News';
    public const URI = 'https://www.steamcommunity.com';
    public const DESCRIPTION = 'Get the latest news for a game on Steam.';
    public const MAINTAINER = 'No maintainer';
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

    private const KNOWN_TAGS = [
        'h1', 'h2', 'h3', 'p', 'b', 'i', 'u', 'strike', 'spoiler',
        'quote', 'code', 'noparse', 'c', 'hr', 'img', 'url',
        'dynamiclink', 'previewyoutube', 'table', 'tr', 'th', 'td',
        'list', 'olist'
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
            throwServerException('Invalid API response format');
        }

        if (isset($json_list['appnews']['newsitems']) === false || is_array($json_list['appnews']['newsitems']) === false) {
            throwServerException('News items not found in API response');
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
        $replacedUrl = preg_replace('/\s/', '%20', $url);
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
            $item['content'] = $this->parseBBCode($contents);
        } else {
            $item['content'] = $contents;
        }

        $item['uid'] = (string) ($json_item['gid'] ?? '');
        return $item;
    }

    private function parseBBCode(string $text): string
    {
        $text = str_ireplace('[/hr]', '', $text);
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace_callback(
            '/\\\\?\[(\/?)([a-z0-9*]+)([^\]]*?)\\\\?\]/i',
            function ($matches) {
                $isClosing = $matches[1];
                $tagName = strtolower($matches[2] ?? '');

                if (in_array($tagName, self::KNOWN_TAGS, true) === false) {
                    return $matches[0];
                }

                $params = $matches[3];
                $params = str_replace('&quot;', '"', $params);
                $params = str_replace('&#039;', "'", $params);
                return '[' . $isClosing . $tagName . $params . ']';
            },
            $text
        ) ?? $text;

        $text = $this->processLists($text);
        $text = $this->processTags($text);
        $text = nl2br($text);

        $text = str_replace(
            '{STEAM_CLAN_IMAGE}',
            'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/clans',
            $text
        );

        return $text;
    }

    private function processLists(string $text): string
    {
        $maxIterations = 10;
        $iteration = 0;

        while (preg_match('/\[(list|olist)\](.*?)\[\/\1\]/is', $text) === 1 && $iteration < $maxIterations) {
            $text = preg_replace_callback(
                '/\[(list|olist)\](.*?)\[\/\1\]/is',
                function ($matches) {
                    return $this->renderList($matches[1], $matches[2]);
                },
                $text
            ) ?? $text;

            $iteration++;
        }

        return $text;
    }

    private function renderList(string $tag, string $content): string
    {
        $content = preg_replace('/\[\s*\*\s*\]/i', '|SEP|', $content) ?? $content;
        $content = preg_replace('/\[\s*\/\*\s*\]/i', '|SEP|', $content) ?? $content;
        $content = preg_replace('/(\|SEP\|\s*)+/', '|SEP|', $content) ?? $content;

        $content = trim($content);
        $content = preg_replace('/^\|SEP\|\s*/', '', $content) ?? $content;
        $content = preg_replace('/\s*\|SEP\|$/', '', $content) ?? $content;

        $items = explode('|SEP|', $content);

        $html = '<' . ($tag === 'olist' ? 'ol' : 'ul') . '>';
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '') {
                $html .= '<li>' . $item . '</li>';
            }
        }
        $html .= '</' . ($tag === 'olist' ? 'ol' : 'ul') . '>';

        return $html;
    }

    private function processTags(string $text): string
    {
        $patterns = [
            '/\[h1\](.*?)\[\/h1\]/is' => '<h1>$1</h1>',
            '/\[h2\](.*?)\[\/h2\]/is' => '<h2>$1</h2>',
            '/\[h3\](.*?)\[\/h3\]/is' => '<h3>$1</h3>',

            '/\[p(?:\s+align="([^"]*)")?\](.*?)\[\/p\]/is' => function ($matches) {
                $align = $matches[1] ?? '';
                $content = $matches[2];
                $style = $align !== '' ? ' style="text-align: ' . htmlspecialchars($align, ENT_QUOTES, 'UTF-8') . ';"' : '';
                return '<p' . $style . '>' . $content . '</p>';
            },

            '/\[b\](.*?)\[\/b\]/is' => '<strong>$1</strong>',
            '/\[i\](.*?)\[\/i\]/is' => '<em>$1</em>',
            '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
            '/\[strike\](.*?)\[\/strike\]/is' => '<s>$1</s>',

            '/\[spoiler\](.*?)\[\/spoiler\]/is' => '<details><summary>Spoiler</summary>$1</details>',
            '/\[quote(?:=[^]]*)?\](.*?)\[\/quote\]/is' => '<blockquote>$1</blockquote>',
            '/\[code\](.*?)\[\/code\]/is' => '<pre><code>$1</code></pre>',
            '/\[noparse\](.*?)\[\/noparse\]/is' => '<span class="noparse">$1</span>',

            '/\[c\](.*?)\[\/c\]/is' => '<code>$1</code>',

            '/\[hr\]/is' => '<hr>',

            '/\[img\s+src="([^"]*)"\]\[\/img\]/is' => function ($matches) {
                $src = $matches[1];
                return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8')
                    . '" alt="" style="max-width: 1600px; max-height: 1200px; display: block; margin-bottom: 16px;" />';
            },
            '/\[img\](https?:\/\/[^"\[\]]+)\[\/img\]/is' => function ($matches) {
                return '<img src="' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8')
                    . '" alt="" style="max-width: 1600px; max-height: 1200px; display: block; margin-bottom: 16px;" />';
            },

            '/\[url="([^"]*)"(?:\s+style="([^"]*)")?\](.*?)\[\/url\]/is' => function ($matches) {
                $href = $matches[1];
                $style = $matches[2] ?? '';
                $text = $matches[3];
                $class = $style === 'button' ? ' class="steam-button"' : '';
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $class . '>' . $text . '</a>';
            },
            '/\[url="([^"]*)"\](.*?)\[\/url\]/is' => function ($matches) {
                return '<a href="' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '">' . $matches[2] . '</a>';
            },
            '/\[url\](https?:\/\/[^"\[\]]+)\[\/url\]/is' => function ($matches) {
                $url = $matches[1];
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>';
            },

            '/\[dynamiclink\s+href="([^"]*)"\](.*?)\[\/dynamiclink\]/is' => function ($matches) {
                $href = $matches[1];
                $text = $matches[2] !== '' ? $matches[2] : $href;
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="dynamic-link">' . $text . '</a>';
            },

            '/\[previewyoutube="([^;"]+)(?:;[^\]]*)?"\](.*?)\[\/previewyoutube\]/is' => function ($matches) {
                $videoId = $matches[1];
                if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId) !== 1) {
                    return '';
                }
                $embedUrl = 'https://www.youtube.com/embed/' . $videoId;
                $safeUrl = htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8');
                $attrs = "width=\"560\" height=\"315\" src=\"{$safeUrl}\" frameborder=\"0\" allow=\"accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture\" allowfullscreen";
                return '<div class="youtube-embed" style="margin-bottom: 16px;"><iframe ' . $attrs . '></iframe></div>';
            },

            '/\[table(?:\s+[^\]]*)?\](.*?)\[\/table\]/is' => '<table>$1</table>',
            '/\[tr\](.*?)\[\/tr\]/is' => '<tr>$1</tr>',
            '/\[th(?:\s+[^\]]*)?\](.*?)\[\/th\]/is' => '<th>$1</th>',
            '/\[td(?:\s+[^\]]*)?\](.*?)\[\/td\]/is' => '<td>$1</td>',

            '/\\\\\[(.*?)\]/s' => '[$1]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (is_callable($replacement) === true) {
                $text = preg_replace_callback($pattern, $replacement, $text) ?? $text;
            } else {
                $text = preg_replace($pattern, $replacement, $text) ?? $text;
            }
        }

        return $text;
    }
}
