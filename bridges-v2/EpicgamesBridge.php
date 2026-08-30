<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class EpicgamesBridge extends BridgeAbstract
{
    public const NAME = 'Epic Games Store News';
    public const URI = 'https://www.epicgames.com';
    public const DESCRIPTION = 'Returns the latest posts from epicgames.com';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            'postcount' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => true,
                'title' => 'Maximum number of items to return',
                'defaultValue' => 10,
            ],
            'language' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => [
                    'English' => 'en',
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
                'title' => 'Language of blog posts',
                'defaultValue' => 'en',
            ],
        ]
    ];

    public function collectData(): void
    {
        $api = 'https://store-content.ak.epicgames.com/api/';

        $languageInput = $this->getInput('language');
        if (is_string($languageInput) === true && $languageInput !== '') {
            $language = $languageInput;
        } else {
            $language = 'en';
        }

        $postcountInput = $this->getInput('postcount');
        if (is_numeric($postcountInput) === true) {
            $postcount = (int) $postcountInput;
        } else {
            $postcount = 10;
        }

        $urlSticky = $api . $language . '/content/blog/sticky';
        $urlBlog = $api . $language . '/content/blog?limit=' . $postcount;

        $dataSticky = getContents($urlSticky);
        $dataBlog = getContents($urlBlog);

        $decodedSticky = json_decode($dataSticky, false);
        $decodedBlog = json_decode($dataBlog, false);

        $merged = [];
        if (is_array($decodedSticky) === true) {
            $merged = array_merge($merged, $decodedSticky);
        }
        if (is_array($decodedBlog) === true) {
            $merged = array_merge($merged, $decodedBlog);
        }

        foreach ($merged as $value) {
            if (is_object($value) === false) {
                continue;
            }

            $item = [];

            $url = (string) ($value->url ?? '');
            if ($url !== '' && str_starts_with($url, '/') === true) {
                $url = self::URI . $url;
            }
            $item['uri'] = $url;

            $item['title'] = (string) ($value->title ?? '');

            $dateStr = (string) ($value->date ?? '');
            if ($dateStr !== '') {
                $timestamp = strtotime($dateStr);
                if ($timestamp !== false) {
                    $item['timestamp'] = $timestamp;
                }
            }

            $author = (string) ($value->author ?? '');
            if ($author !== '') {
                $item['author'] = $author;
            } else {
                $item['author'] = 'Epic Games Store';
            }

            $contentRaw = (string) ($value->content ?? '');
            $image = (string) ($value->image ?? '');

            $content = '';
            if ($image !== '') {
                $content .= '<p><img src="' . htmlspecialchars($image) . '" style="' . self::CSS['img'] . '" alt="" /></p>';
            }

            if ($contentRaw !== '') {
                $content .= $this->processContent($contentRaw);
            }

            $item['content'] = $content;

            $uid = (string) ($value->_id ?? '');
            $item['uid'] = $uid;

            $this->items[] = $item;
        }

        usort($this->items, function (array $a, array $b): int {
            $tsA = $a['timestamp'] ?? 0;
            $tsB = $b['timestamp'] ?? 0;
            return $tsB <=> $tsA;
        });

        $this->items = array_slice($this->items, 0, $postcount);
    }

    private function processContent(string $html): string
    {
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
        libxml_use_internal_errors(false);

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return $html;
        }

        foreach ($wrapper->querySelectorAll('a[href], img[src], source[src]') as $el) {
            if ($el instanceof \Dom\Element === false) {
                continue;
            }

            $tag = strtolower($el->nodeName);
            $attrName = ($tag === 'a') ? 'href' : 'src';
            $attr = (string) ($el->getAttribute($attrName) ?? '');

            if ($attr === '') {
                continue;
            }

            if (str_starts_with($attr, 'http://') === true || str_starts_with($attr, 'https://') === true || str_starts_with($attr, '//') === true) {
                $resolved = $attr;
            } elseif (str_starts_with($attr, '/') === true) {
                $parsed = parse_url(self::URI);
                $scheme = (string) ($parsed['scheme'] ?? 'https');
                $host = (string) ($parsed['host'] ?? '');
                $resolved = $scheme . '://' . $host . $attr;
            } else {
                $resolved = rtrim(self::URI, '/') . '/' . ltrim($attr, '/');
            }

            $el->setAttribute($attrName, $resolved);
        }

        foreach ($wrapper->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::CSS['img']);
            }
        }

        return (string) $wrapper->innerHTML;
    }
}
