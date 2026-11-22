<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class LogicMastersBridge extends BridgeAbstract
{
    public const NAME = 'Logic Masters Deutschland e.V.';
    public const URI = 'https://logic-masters.de/';
    public const DESCRIPTION = 'Aktuelles';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $html = $this->fetchHtml(self::URI);
        $items = $html->querySelectorAll('div.aktuelles_eintrag');

        foreach ($items as $node) {
            $titleNode = $node->querySelector('div.aktuelles_titel');
            $dateNode = $node->querySelector('div.aktuelles_datum');
            $contentNodes = $node->querySelectorAll('p');

            $title = $titleNode !== null ? trim($titleNode->textContent ?? '') : '';
            if ($title === '') {
                $title = 'Untitled';
            }

            $dateText = $dateNode !== null ? trim($dateNode->textContent ?? '') : '';
            $timestamp = $dateText !== '' ? $this->parseDate($dateText) : null;

            $content = '';
            foreach ($contentNodes as $p) {
                $htmlContent = $p->innerHTML;
                if ($htmlContent !== '') {
                    $content .= '<p>' . $this->makeUrlsAbsolute($htmlContent) . '</p>';
                }
            }

            if ($content === '') {
                $content = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
            }

            $feedItem = [
                'title' => $title,
                'uri' => self::URI,
                'content' => $content,
                'uid' => md5($title . '|' . $dateText),
            ];

            if ($timestamp !== null) {
                $feedItem['timestamp'] = $timestamp;
            }

            $this->items[] = $feedItem;
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

    private function parseDate(string $dateString): ?int
    {
        $clean = preg_replace('/\s+/u', ' ', trim($dateString));
        if (is_string($clean) === false) {
            return null;
        }

        if (preg_match('/^(\d{1,2})\.\s*(\p{L}+)\s+(\d{4})$/u', $clean, $m) !== 1) {
            return null;
        }

        $month = match (mb_strtolower($m[2])) {
            'januar', 'jänner' => 1,
            'februar', 'feber' => 2,
            'märz', 'maerz' => 3,
            'april' => 4,
            'mai' => 5,
            'juni' => 6,
            'juli' => 7,
            'august' => 8,
            'september' => 9,
            'oktober' => 10,
            'november' => 11,
            'dezember' => 12,
            default => 0,
        };

        if ($month === 0) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            'Y-n-j H:i:s',
            sprintf('%04d-%d-%d 00:00:00', (int) $m[3], $month, (int) $m[1]),
            new \DateTimeZone('Europe/Berlin')
        );

        return $date === false ? null : $date->getTimestamp();
    }

    private function fetchHtml(string $url): \Dom\HTMLDocument
    {
        $html = getContents($url);

        if ($html === '') {
            throwServerException('Failed to fetch ' . $url);
        }

        $dom = parseHtmlDocument($html);

        return $dom;
    }
}
