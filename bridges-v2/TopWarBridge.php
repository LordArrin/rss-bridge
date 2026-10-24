<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class TopWarBridge extends BridgeAbstract
{
    public const NAME = 'TopWar';
    public const URI = 'https://topwar.ru/';
    public const DESCRIPTION = 'Fetches full articles from TopWar.ru';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        [
            'feed' => [
                'name' => 'Feed Section',
                'type' => 'list',
                'values' => [
                    'All News (Main)' => 'rssdzen',
                    'News' => 'news/rssdzen',
                    'Armament' => 'armament/rssdzen',
                    'History' => 'history/rssdzen',
                    'Opinions' => 'opinions/rssdzen',
                    'Video' => 'video/rssdzen',
                ],
                'defaultValue' => 'rssdzen'
            ]
        ]
    ];

    public function collectData(): void
    {
        $feedSlug = $this->getInput('feed') ?? 'rssdzen';
        $url = sprintf('https://topwar.ru/%s.xml', $feedSlug);

        $xmlString = getContents($url);
        if ($xmlString === '') {
            return;
        }

        $parser = new \RSSBridge\FeedParser();
        try {
            $feed = $parser->parseFeed($xmlString);
        } catch (\Exception $e) {
            return;
        }

        foreach ($feed['items'] as $item) {
            $articleUrl = $item['uri'] ?? '';
            if ($articleUrl === '') {
                continue;
            }

            $fullContent = $this->fetchFullArticle($articleUrl);
            if ($fullContent === '') {
                continue;
            }

            $title = $item['title'] !== '' ? $item['title'] : 'Untitled';
            $timestamp = $item['timestamp'] !== '' ? (int) $item['timestamp'] : time();
            $author = $item['author'] !== '' ? $item['author'] : null;

            $this->items[] = [
                'title'     => $title,
                'uri'       => $articleUrl,
                'content'   => $fullContent,
                'timestamp' => $timestamp,
                'author'    => $author,
                'uid'       => $articleUrl,
            ];
        }
    }

    private function fetchFullArticle(string $url): string
    {
        try {
            $html = getSimpleHTMLDOM($url);
        } catch (\Exception $e) {
            return '';
        }

        $articleBody = $html->querySelector('div.full-story-text.text');

        if ($articleBody === null) {
            return '';
        }

        $banners = $articleBody->querySelectorAll('div.banner-full-story, div.banner-block');
        foreach ($banners as $banner) {
            $banner->parentNode?->removeChild($banner);
        }

        $metas = $articleBody->querySelectorAll('meta');
        foreach ($metas as $meta) {
            $meta->parentNode?->removeChild($meta);
        }

        $this->processImagesAndCaptions($articleBody);
        $this->processHeadings($articleBody);

        $content = $this->getInnerHTML($articleBody);

        return $this->fixEscapedIframes($content);
    }

    private function getInnerHTML($element): string
    {
        if ($element === null || $element->hasChildNodes() === false) {
            return '';
        }

        $innerHTML = '';
        $ownerDoc = $element->ownerDocument;

        foreach ($element->childNodes as $child) {
            $innerHTML .= $ownerDoc->saveHTML($child);
        }

        return $innerHTML;
    }

    private function fixEscapedIframes(string $html): string
    {
        $html = preg_replace('/&lt;(iframe\s[^>]*)\s*&gt;/i', '<$1>', $html);
        $html = preg_replace('/&lt;\/iframe\s*&gt;/i', '</iframe>', $html);
        $html = preg_replace('/<[\x{200B}-\x{200D}\x{FEFF}\x{200C}]iframe/iu', '<iframe', $html);

        return $html;
    }

    private function processImagesAndCaptions($articleBody): void
    {
        $imgContainers = $articleBody->querySelectorAll('div[style*="text-align:center"]');

        foreach ($imgContainers as $imgContainer) {
            $img = $imgContainer->querySelector('img');
            if ($img === null) {
                continue;
            }

            $src = $img->getAttribute('src');
            if ($src !== '' && str_starts_with($src, 'http') === false) {
                $src = 'https://topwar.ru' . $src;
                $img->setAttribute('src', $src);
            }

            $img->removeAttribute('align');
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->removeAttribute('class');
            $img->removeAttribute('loading');

            $img->setAttribute('style', 'max-width: 100%; height: auto; display: block;');
            $imgContainer->setAttribute('style', 'display: block; clear: both; max-width: 100%; margin: 20px 0; text-align: left;');

            $nextSibling = $imgContainer->nextSibling;

            while ($nextSibling !== null && $nextSibling->nodeType === XML_TEXT_NODE && trim($nextSibling->textContent ?? '') === '') {
                $nextSibling = $nextSibling->nextSibling;
            }

            if ($nextSibling !== null && $nextSibling->nodeType === XML_ELEMENT_NODE) {
                $tagName = $nextSibling->tagName ?? '';
                if ($tagName === 'div' || $tagName === 'span') {
                    $captionText = trim($nextSibling->textContent ?? '');
                    if ($captionText !== '' && strlen($captionText) < 500) {
                        $captionWrapper = $articleBody->ownerDocument->createElement('div');
                        $captionWrapper->setAttribute('style', 'font-size: 0.9em; margin-top: 8px; margin-bottom: 15px;');
                        $captionWrapper->textContent = $captionText;

                        $imgContainer->parentNode?->insertBefore($captionWrapper, $nextSibling->nextSibling);
                        $nextSibling->parentNode?->removeChild($nextSibling);
                    }
                }
            }
        }
    }

    private function processHeadings($articleBody): void
    {
        $h3Tags = $articleBody->querySelectorAll('h3');
        foreach ($h3Tags as $h3) {
            $style = $h3->getAttribute('style') ?? '';
            $newStyle = rtrim($style, '; ') . '; margin: 25px 0 15px 0; font-weight: bold; font-size: 1.2em;';
            $h3->setAttribute('style', $newStyle);
        }

        $h2Tags = $articleBody->querySelectorAll('h2');
        foreach ($h2Tags as $h2) {
            $style = $h2->getAttribute('style') ?? '';
            $newStyle = rtrim($style, '; ') . '; margin: 30px 0 20px 0; font-weight: bold; font-size: 1.4em;';
            $h2->setAttribute('style', $newStyle);
        }
    }
}
