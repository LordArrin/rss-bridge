<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class TheFarSideBridge extends BridgeAbstract
{
    public const NAME = 'The Far Side';
    public const URI = 'https://www.thefarside.com';
    public const DESCRIPTION = 'Returns the daily dose';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $html = $this->fetchHtml(self::URI);

        $container = $html->querySelector('div.tfs-page-container__cows');
        if ($container === null) {
            throwServerException('Container not found');
        }

        $metaUrl = $html->querySelector('meta[property="og:url"]');
        $uri = self::URI;
        if ($metaUrl !== null) {
            $content = $metaUrl->getAttribute('content');
            if ($content !== null && $content !== '') {
                $uri = $content;
            }
        }

        $titleNode = $container->querySelector('h3');
        if ($titleNode === null) {
            throwServerException('Title not found');
        }

        $title = $titleNode->innerHTML;
        $timestamp = strtotime($title);
        if ($timestamp === false) {
            $timestamp = time();
        }

        $content = '';

        $cards = $container->querySelectorAll('div.card-body');
        foreach ($cards as $card) {
            $image = $card->querySelector('img');
            if ($image === null) {
                continue;
            }

            $imageUrl = $image->getAttribute('data-src');
            if ($imageUrl === null || $imageUrl === '') {
                $imageUrl = $image->getAttribute('src');
            }

            if ($imageUrl === null || $imageUrl === '') {
                continue;
            }

            $figcaption = $card->querySelector('figcaption');
            $caption = '';
            if ($figcaption !== null) {
                $caption = $figcaption->innerHTML;
            }

            $content .= '<figure>';
            $content .= '<img title="' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '" src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '"/>';
            $content .= '<figcaption>' . $caption . '</figcaption>';
            $content .= '</figure>';
            $content .= '<br/>';
        }

        if ($content === '') {
            throwServerException('No comics found');
        }

        $this->items[] = [
            'uri' => $uri,
            'title' => $title,
            'timestamp' => $timestamp,
            'content' => $content,
            'uid' => md5($title . (string) $timestamp),
        ];
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
