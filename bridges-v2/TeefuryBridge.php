<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class TeefuryBridge extends BridgeAbstract
{
    public const NAME = 'Teefury';
    public const URI = 'https://www.teefury.com';
    public const DESCRIPTION = 'Returns the daily designs';
    public const MAINTAINER = 'no maintainer';
    public const CACHE_TIMEOUT = 10800;

    public function collectData(): void
    {
        $html = $this->fetchHtml(self::URI);
        $cards = $html->querySelectorAll('div.odad-card__wrapper');

        $today = date('m/d/Y');

        foreach ($cards as $card) {
            $titleNode = $card->querySelector('p.odad-title');
            $productDiv = $card->querySelector('div[id*="img-color-art"]');
            $imgNode = $card->querySelector('div[id*="img-color-art"] img');

            if ($titleNode === null || $productDiv === null || $imgNode === null) {
                continue;
            }

            $titleHtml = $titleNode->innerHTML;
            $parts = explode('<br>', $titleHtml);

            $title = 'Untitled';
            if (isset($parts[0]) === true) {
                $title = trim($parts[0]);
            }

            $authorLink = $titleNode->querySelector('a.odad-title__anchor');
            $author = 'Unknown';
            if ($authorLink !== null) {
                $author = trim($authorLink->textContent);
            }

            $dataLink = $productDiv->getAttribute('data-link');
            $uri = self::URI;
            if ($dataLink !== null && $dataLink !== '') {
                $uri = urljoin(self::URI, $dataLink);
            }

            $imgSrc = $imgNode->getAttribute('src');
            $imgUrl = '';
            if ($imgSrc !== null && $imgSrc !== '') {
                $imgUrl = urljoin(self::URI, $imgSrc);
            }

            $content = '<a href="' . htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') . '">';
            $content .= '<img src="' . htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') . '" />';
            $content .= '</a>';

            $this->items[] = [
                'title' => $title,
                'author' => $author,
                'uri' => $uri,
                'timestamp' => strtotime($today),
                'uid' => md5($title . $author),
                'content' => $content,
            ];
        }

        if ($this->items === []) {
            throwServerException('No products found');
        }
    }

    private function fetchHtml(string $url): \Dom\HTMLDocument
    {
        $html = getContents($url);

        if ($html === '') {
            throwServerException('Failed to fetch ' . $url);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }
}
