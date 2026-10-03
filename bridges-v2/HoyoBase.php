<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use Json;

abstract class HoyoBase extends BridgeAbstract
{
    public const LANGUAGE_DEFAULT = 'en-us';
    public const LIMIT_MIN = 1;
    public const LIMIT_DEFAULT = 5;
    public const LIMIT_MAX = 20;
    public const CACHE_TIMEOUT = 18000;

    protected const LANGUAGE_VALUES = [
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
        'Vietnamese' => 'vi-vn',
    ];

    public const PARAMETERS = [
        '' => [
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => self::LIMIT_DEFAULT,
            ],
            'language' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => self::LANGUAGE_VALUES,
                'defaultValue' => self::LANGUAGE_DEFAULT,
            ],
        ],
    ];

    protected const IMG_STYLE = 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;';

    abstract protected function getApiUrl(int $limit, string $language): string;
    abstract protected function getArticleUrl(int $infoId): string;
    abstract protected function getBannerKey(): string;

    protected function getBannerUrl(array $jsonExt): string
    {
        $bannerKey = $this->getBannerKey();
        return (string) ($jsonExt[$bannerKey][0]['url'] ?? '');
    }

    protected function processContentHtml(\Dom\Element $wrapper, string $sContent): void
    {
    }

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

        $url = $this->getApiUrl($limit, $language);
        $apiResponse = getContents($url);

        try {
            $jsonList = Json::decode($apiResponse);
        } catch (\Exception $e) {
            throwServerException('Invalid JSON in API response: ' . $e->getMessage());
        }

        if (is_array($jsonList) === false) {
            throwServerException('Invalid API response format');
        }

        $list = $jsonList['data']['list'] ?? null;
        if (is_array($list) === false) {
            return;
        }

        foreach ($list as $jsonItem) {
            if (is_array($jsonItem) === false) {
                continue;
            }

            $sContent = (string) ($jsonItem['sContent'] ?? '');
            if ($sContent === '') {
                continue;
            }

            $dom = \Dom\HTMLDocument::createFromString('<div>' . $sContent . '</div>');
            $wrapper = $dom->querySelector('div');

            if ($wrapper === null) {
                continue;
            }

            $this->processContentHtml($wrapper, $sContent);
            $this->limitImageSize($wrapper);

            $articleHtml = (string) $wrapper->innerHTML;

            $sTitle = (string) ($jsonItem['sTitle'] ?? '');
            $dtStartTime = (string) ($jsonItem['dtStartTime'] ?? '');
            if ($dtStartTime !== '') {
                $timestamp = strtotime($dtStartTime);
                if ($timestamp === false) {
                    $timestamp = time();
                }
            } else {
                $timestamp = time();
            }

            $iInfoId = (int) ($jsonItem['iInfoId'] ?? 0);
            $uri = urljoin(self::URI, $this->getArticleUrl($iInfoId));

            $sExt = (string) ($jsonItem['sExt'] ?? '');
            $bannerUrl = '';

            if ($sExt !== '') {
                $jsonExt = Json::decode($sExt);
                if (is_array($jsonExt) === true) {
                    $bannerUrl = $this->getBannerUrl($jsonExt);
                }
            }

            $content = '';
            if ($bannerUrl !== '') {
                $content .= sprintf(
                    '<p><img src="%s" style="%s" alt="" /></p>',
                    htmlspecialchars($bannerUrl, ENT_QUOTES, 'UTF-8'),
                    self::IMG_STYLE
                );
            }
            $content .= $articleHtml;

            $this->items[] = [
                'title' => $sTitle,
                'timestamp' => $timestamp,
                'content' => $content,
                'uri' => $uri,
                'uid' => (string) $iInfoId,
            ];
        }
    }

    protected function limitImageSize(\Dom\Element $node): void
    {
        foreach ($node->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::IMG_STYLE);
            }
        }
    }

    protected function alignTextLeft(\Dom\Element $node): void
    {
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

    protected function processYoutubeEmbeds(\Dom\Element $node): void
    {
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

            $replacement = handleYoutube($html);
            if (is_string($replacement) === false || $replacement === '') {
                continue;
            }

            $parent = $frame->parentNode;
            if ($parent === null) {
                continue;
            }

            $tempDoc = \Dom\HTMLDocument::createFromString('<div id="rss-bridge-temp-wrapper">' . $replacement . '</div>');

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
