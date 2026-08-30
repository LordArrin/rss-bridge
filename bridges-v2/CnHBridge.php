<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class CnHBridge extends BridgeAbstract
{
    public const NAME = 'Cyanide and Happiness';
    public const URI = 'https://explosm.net/';
    public const DESCRIPTION = 'A Webcomic by Kris Wilson, Rob DenBleyker, and Dave McElfatrick';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 7200;

    private const ITEM_LIMIT = 5;

    public function getURI(): string
    {
        return self::URI . 'comics/latest#comic';
    }

    public function collectData(): void
    {
        $url = $this->getURI();

        for ($i = 0; $i < self::ITEM_LIMIT; $i++) {
            $result = $this->fetchComicPage($url);

            if ($result === null) {
                break;
            }

            $this->items[] = $result['item'];

            $nextUrl = $result['nextUrl'];
            if ($nextUrl === null) {
                break;
            }

            $url = $nextUrl;
        }

        if ($this->items === []) {
            throwServerException('No comics could be extracted');
        }
    }

    private function fetchComicPage(string $url): ?array
    {
        $htmlString = getContents($url);

        if ($htmlString === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($htmlString);
        libxml_use_internal_errors(false);

        $item = $this->extractComicItem($dom);
        if ($item === null) {
            return null;
        }

        $nextUrl = $this->extractNextUrl($dom);

        return [
            'item' => $item,
            'nextUrl' => $nextUrl,
        ];
    }

    private function extractComicItem(\Dom\HTMLDocument $dom): ?array
    {
        $comicElement = $dom->querySelector('[class*=ComicImage]');
        if ($comicElement === null) {
            return null;
        }

        $authorBlocks = $comicElement->querySelectorAll('[class^=Author__Right] p');
        if (count($authorBlocks) < 2) {
            return null;
        }

        $dateText = trim($authorBlocks[0]->textContent);
        $author = str_replace('by ', '', trim($authorBlocks[1]->textContent));

        $imgNode = $comicElement->querySelector('img');
        $imageSrc = $imgNode?->getAttribute('src');

        if ($imageSrc === null || $imageSrc === '') {
            return null;
        }

        $link = $dom->querySelector('[rel=canonical]')?->getAttribute('href');
        if ($link === null || $link === '') {
            return null;
        }

        $safeImageSrc = htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8');

        $timestamp = null;
        $dateNormalized = str_replace('.', '-', $dateText);
        $parsed = strtotime($dateNormalized . 'T00:00:00Z');
        if ($parsed !== false) {
            $timestamp = $parsed;
        }

        return [
            'uid' => $link,
            'author' => $author,
            'title' => $dateText,
            'uri' => $link . '#comic',
            'timestamp' => $timestamp,
            'content' => '<img src="' . $safeImageSrc . '" />',
        ];
    }

    private function extractNextUrl(\Dom\HTMLDocument $dom): ?string
    {
        $href = $dom->querySelector('[class*=ComicSelector] > a')?->getAttribute('href');

        if ($href === null || $href === '') {
            return null;
        }

        return self::URI . $href;
    }
}
