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

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_clear_errors();

        if ($xml === false || isset($xml->channel->item) === false) {
            return;
        }

        foreach ($xml->channel->item as $entry) {
            $articleUrl = (string) ($entry->link ?? '');
            if ($articleUrl === '') {
                continue;
            }

            $fullContent = $this->fetchFullArticle($articleUrl);

            if ($fullContent === '') {
                continue;
            }

            $namespaces = $entry->getNameSpaces(true);
            $dc = isset($namespaces['dc']) === true ? $entry->children($namespaces['dc']) : null;

            $this->items[] = [
                'title' => (string) ($entry->title ?? 'Untitled'),
                'uri' => $articleUrl,
                'content' => $fullContent,
                'timestamp' => isset($entry->pubDate) === true ? strtotime((string) $entry->pubDate) : time(),
                'author' => $dc !== null ? (string) $dc->creator : null,
                'uid' => (string) ($entry->guid ?? $entry->link),
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

        return $this->getInnerHTML($articleBody);
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
