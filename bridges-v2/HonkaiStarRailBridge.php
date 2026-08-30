<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class HonkaiStarRailBridge extends BridgeAbstract
{
    public const NAME = 'Honkai: Star Rail';
    public const URI = 'https://hsr.hoyoverse.com/en-us/news';
    public const DESCRIPTION = 'Latest news from the Honkai: Star Rail website';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 18000;

    public const API_URL = 'https://sg-public-api-static.hoyoverse.com/content_v2_user/app/113fe6d3b4514cdd/getContentList?iPage=1&iPageSize=%u&sLangKey=%s&isPreview=0&iChanId=248';

    public const ARTICLE_URL = '/en-us/news/%u';

    public const LANGUAGE_DEFAULT = 'en-us';
    public const LIMIT_MIN = 1;
    public const LIMIT_DEFAULT = 5;
    public const LIMIT_MAX = 20;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => self::LIMIT_DEFAULT
            ],
            'language' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => [
                    'Chinese' => 'zh-tw',
                    'English' => 'en-us',
                    'French' => 'fr-fr',
                    'German' => 'de-de',
                    'Indonesian' => 'id-id',
                    'Japanese' => 'ja-jp',
                    'Korean' => 'ko-kr',
                    'Portuguese' => 'pt-pt',
                    'Russian' => 'ru-ru',
                    'Spanish' => 'es-es',
                    'Thai' => 'th-th',
                    'Vietnamese' => 'vi-vn'
                ],
                'defaultValue' => self::LANGUAGE_DEFAULT
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

        $languageInput = $this->getInput('language');
        if (is_string($languageInput) === true && $languageInput !== '') {
            $language = $languageInput;
        } else {
            $language = self::LANGUAGE_DEFAULT;
        }

        $url = sprintf(self::API_URL, $limit, $language);
        $api_response = getContents($url);
        $json_list = \Json::decode($api_response);

        if (is_array($json_list) === false) {
            \throwServerException('Invalid API response format');
        }

        $list = $json_list['data']['list'] ?? null;
        if (is_array($list) === false) {
            return;
        }

        foreach ($list as $json_item) {
            if (is_array($json_item) === false) {
                continue;
            }

            $sContent = (string) ($json_item['sContent'] ?? '');
            if ($sContent === '') {
                continue;
            }

            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString('<div>' . $sContent . '</div>');
            libxml_use_internal_errors(false);

            $wrapper = $dom->querySelector('div');
            if ($wrapper === null) {
                continue;
            }

            $this->limitImageSize($wrapper);
            $this->alignTextLeft($wrapper);
            $this->processYoutubeEmbeds($wrapper);

            $articleHtml = (string) $wrapper->innerHTML;

            $sTitle = (string) ($json_item['sTitle'] ?? '');
            $dtStartTime = (string) ($json_item['dtStartTime'] ?? '');
            if ($dtStartTime !== '') {
                $timestamp = strtotime($dtStartTime);
                if ($timestamp === false) {
                    $timestamp = time();
                }
            } else {
                $timestamp = time();
            }

            $iInfoId = (int) ($json_item['iInfoId'] ?? 0);
            $uri = self::URI . sprintf(self::ARTICLE_URL, $iInfoId);

            $sExt = (string) ($json_item['sExt'] ?? '');
            $bannerUrl = '';
            if ($sExt !== '') {
                $json_ext = \Json::decode($sExt);
                if (is_array($json_ext) === true && isset($json_ext['news-poster'][0]['url']) === true) {
                    $bannerUrl = (string) $json_ext['news-poster'][0]['url'];
                }
            }

            $content = '';
            if ($bannerUrl !== '') {
                $content .= '<p><img src="' . htmlspecialchars($bannerUrl) . '" style="' . self::CSS['img'] . '" alt="" /></p>';
            }
            $content .= $articleHtml;

            $item = [
                'title' => $sTitle,
                'timestamp' => $timestamp,
                'content' => $content,
                'uri' => $uri,
                'uid' => (string) $iInfoId,
            ];

            $this->items[] = $item;
        }
    }

    private function limitImageSize(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::CSS['img']);
            }
        }
    }

    private function alignTextLeft(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('p, div, h1, h2, h3, h4, h5, h6') as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $style = (string) ($element->getAttribute('style') ?? '');
            if ($style !== '') {
                $newStyle = preg_replace('/text-align\s*:\s*center\s*;?/i', '', $style);
                if (is_string($newStyle) === true && $newStyle !== $style) {
                    $newStyle = trim($newStyle);
                    if ($newStyle === '') {
                        $element->removeAttribute('style');
                    } else {
                        $element->setAttribute('style', $newStyle);
                    }
                }
            }

            $element->setAttribute('align', 'left');
        }
    }

    private function processYoutubeEmbeds(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        if (function_exists('handleYoutube') === false) {
            return;
        }

        $youtubeFrames = $node->querySelectorAll('div.ttr-video-frame');
        foreach ($youtubeFrames as $frame) {
            if ($frame instanceof \Dom\Element === false) {
                continue;
            }

            $html = $frame->ownerDocument->saveHTML($frame);
            if (is_string($html) === false || $html === '') {
                continue;
            }

            $replacement = \handleYoutube($html);
            if (is_string($replacement) === false || $replacement === '') {
                continue;
            }

            $parent = $frame->parentNode;
            if ($parent === null) {
                continue;
            }

            libxml_use_internal_errors(true);
            $tempDoc = \Dom\HTMLDocument::createFromString('<div id="rss-bridge-temp-wrapper">' . $replacement . '</div>');
            libxml_use_internal_errors(false);

            $tempWrapper = $tempDoc->querySelector('#rss-bridge-temp-wrapper');
            if ($tempWrapper === null) {
                continue;
            }

            $importedNodes = [];
            foreach ($tempWrapper->childNodes as $child) {
                $imported = $frame->ownerDocument->importNode($child, true);
                if ($imported !== null) {
                    $importedNodes[] = $imported;
                }
            }

            foreach ($importedNodes as $importedNode) {
                $parent->insertBefore($importedNode, $frame);
            }

            $parent->removeChild($frame);
        }
    }
}
