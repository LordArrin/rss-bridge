<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class TomsToucheBridge extends BridgeAbstract
{
    public const NAME = 'Toms Touche';
    public const URI = 'https://taz.de/#!tom=tomdestages';
    public const DESCRIPTION = 'Your daily dose of Toms Touche.';
    public const MAINTAINER = 'no maintainer';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $url = 'https://taz.de/';
        $html = getContents($url);
        if ($html === '') {
            throwServerException('Failed to fetch page content.');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $dateNodes = iterator_to_array($dom->querySelectorAll('p[x-ref]'));
        if (count($dateNodes) === 0) {
            throwServerException('Could not find the date element.');
        }

        $dateNode = $dateNodes[0];
        if ($dateNode instanceof \Dom\Element === false) {
            throwServerException('Date node is not an element.');
        }

        $dateStr = trim($dateNode->textContent);
        $parts = explode('.', $dateStr);
        if (count($parts) !== 3) {
            throwServerException('Invalid date format.');
        }

        $day = (int) $parts[0];
        $month = (int) $parts[1];
        $year = (int) $parts[2];

        $image = $dom->querySelector('img[alt="tom des tages"]');
        if ($image instanceof \Dom\Element === false) {
            throwServerException('Could not find the comic image.');
        }

        $src = (string) $image->getAttribute('src');
        if ($src === '') {
            throwServerException('Image src is empty.');
        }

        if (str_starts_with($src, 'http://') === true || str_starts_with($src, 'https://') === true) {
            $imgUrl = $src;
        } else {
            $imgUrl = 'https://taz.de/' . ltrim($src, '/');
        }

        $timestamp = mktime(0, 0, 0, $month, $day, $year);
        if ($timestamp === false) {
            $timestamp = time();
        }

        $alt = htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8');
        $srcEscaped = htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8');
        $content = sprintf('<img src="%s" alt="%s" />', $srcEscaped, $alt);

        $this->items[] = [
            'title' => 'Toms Touche - ' . $dateStr,
            'uri' => self::URI,
            'timestamp' => $timestamp,
            'content' => $content,
            'uid' => $imgUrl,
        ];
    }
}
