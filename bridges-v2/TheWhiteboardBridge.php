<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class TheWhiteboardBridge extends BridgeAbstract
{
    public const NAME = 'The Whiteboard';
    public const URI = 'https://www.the-whiteboard.com/';
    public const DESCRIPTION = 'Get the latest comic from The Whiteboard';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $html = getContents(self::URI);
        if ($html === '') {
            throwServerException('Failed to fetch page content.');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $centers = iterator_to_array($dom->querySelectorAll('center'));
        if (count($centers) < 2) {
            throwServerException('Could not find the comic container on the page.');
        }

        $comicContainer = $centers[1];
        if ($comicContainer instanceof \Dom\Element === false) {
            throwServerException('Comic container is not a valid element.');
        }

        $img = $comicContainer->querySelector('img');

        $imgSrc = (string) $img?->getAttribute('src');
        if ($imgSrc === '') {
            throwServerException('Could not find the comic image.');
        }

        if (str_starts_with($imgSrc, 'http://') === true || str_starts_with($imgSrc, 'https://') === true) {
            $imgUrl = $imgSrc;
        } else {
            $imgUrl = rtrim(self::URI, '/') . '/' . ltrim($imgSrc, '/');
        }

        $text = trim((string) $comicContainer->textContent);
        $lines = preg_split('/\r\n|\n|\r/', $text);
        $dateStr = trim($lines[0] ?? '');

        $timestamp = strtotime($dateStr);
        if ($timestamp === false) {
            $timestamp = time();
        }

        $alt = htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8');
        $src = htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8');
        $content = sprintf('<img src="%s" alt="%s" />', $src, $alt);

        $this->items[] = [
            'title' => $dateStr,
            'uri' => $imgUrl,
            'content' => $content,
            'timestamp' => $timestamp,
            'uid' => $imgUrl,
        ];
    }
}
