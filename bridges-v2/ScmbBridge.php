<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ScmbBridge extends BridgeAbstract
{
    public const NAME = 'Se Coucher Moins Bete';
    public const URI = 'https://secouchermoinsbete.fr';
    public const DESCRIPTION = 'Returns the newest anecdotes';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 21600;

    public function collectData(): void
    {
        $html = $this->fetchHtml(self::URI);

        $articles = $html->querySelectorAll('article');

        foreach ($articles as $article) {
            $summaryLink = $article->querySelector('p.summary a');
            $titleLink = $article->querySelector('header h1 a');
            $timeNode = $article->querySelector('time');

            if ($summaryLink === null || $titleLink === null || $timeNode === null) {
                continue;
            }

            $href = $summaryLink->getAttribute('href');
            if ($href === null || $href === '') {
                continue;
            }

            $uri = self::URI . $href;
            $title = $titleLink->innerHTML;

            $readMoreButton = $article->querySelector('span.read-more');
            if ($readMoreButton !== null) {
                $readMoreButton->remove();
            }

            $content = $summaryLink->innerHTML;
            $contentLength = mb_strlen($content, 'UTF-8');
            if ($contentLength > 17) {
                $content = mb_substr($content, 0, $contentLength - 17, 'UTF-8');
            }

            $datetime = $timeNode->getAttribute('datetime');
            if ($datetime === null || $datetime === '') {
                continue;
            }

            $parts = explode(' ', $datetime);
            if (count($parts) !== 2) {
                continue;
            }

            $dateParts = explode('-', $parts[0]);
            $timeParts = explode(':', $parts[1]);

            if (count($dateParts) !== 3 || count($timeParts) < 2) {
                continue;
            }

            $y = (int) $dateParts[0];
            $m = (int) $dateParts[1];
            $d = (int) $dateParts[2];
            $h = (int) $timeParts[0];
            $i = (int) $timeParts[1];

            $timestamp = mktime($h, $i, 0, $m, $d, $y);
            if ($timestamp === false) {
                $timestamp = time();
            }

            $this->items[] = [
                'title' => $title,
                'uri' => $uri,
                'timestamp' => $timestamp,
                'content' => $content,
                'uid' => md5($uri),
            ];
        }

        if ($this->items === []) {
            throwServerException('No anecdotes found');
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
