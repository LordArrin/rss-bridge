<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class LogicMastersBridge extends BridgeAbstract
{
    public const NAME = 'Logic Masters Deutschland e.V.';
    public const URI = 'https://logic-masters.de/';
    public const DESCRIPTION = 'Aktuelles';
    public const MAINTAINER = 'no maintainer';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $html = $this->fetchHtml(self::URI);
        $items = $html->querySelectorAll('div.aktuelles_eintrag');

        foreach ($items as $item) {
            $titleNode = $item->querySelector('div.aktuelles_titel');
            $dateNode = $item->querySelector('div.aktuelles_datum');
            $contentNodes = $item->querySelectorAll('p');

            $title = 'Untitled';
            if ($titleNode !== null) {
                $title = trim($titleNode->textContent);
            }

            $timestamp = time();
            if ($dateNode !== null) {
                $timestamp = $this->parseDate(trim($dateNode->textContent));
            }

            $content = '';

            foreach ($contentNodes as $p) {
                $htmlContent = $p->innerHTML;
                if ($htmlContent !== '') {
                    $processedHtml = $this->makeUrlsAbsolute($htmlContent);
                    $content .= '<p>' . $processedHtml . '</p>';
                }
            }

            if ($content === '') {
                $content = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
            }

            $this->items[] = [
                'title' => $title,
                'uri' => self::URI,
                'content' => $content,
                'timestamp' => $timestamp,
                'uid' => md5($title . (string) $timestamp),
            ];
        }

        if ($this->items === []) {
            throwServerException('No news items found');
        }
    }

    private function makeUrlsAbsolute(string $html): string
    {
        $pattern = '/\b(href|src)=["\']([^"\']+)["\']/i';

        return preg_replace_callback(
            $pattern,
            function (array $matches): string {
                $attr = $matches[1];
                $url = $matches[2];

                if (str_starts_with($url, 'http://') === true || str_starts_with($url, 'https://') === true || str_starts_with($url, '#') === true) {
                    return $matches[0];
                }

                $absoluteUrl = urljoin(self::URI, $url);
                return $attr . '="' . htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') . '"';
            },
            $html
        ) ?? $html;
    }

    private function parseDate(string $dateString): int
    {
        $cleanDate = str_replace(['\\.', '\\'], ['.', ''], $dateString);

        $formatter = new \IntlDateFormatter(
            'de',
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE
        );
        $result = $formatter->parse($cleanDate);

        if ($result === false) {
            return time();
        }

        return $result;
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
