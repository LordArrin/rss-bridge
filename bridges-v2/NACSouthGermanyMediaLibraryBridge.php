<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class NACSouthGermanyMediaLibraryBridge extends BridgeAbstract
{
    public const NAME = 'NAK Süd Mediathek';
    public const URI = 'https://nak-sued.de';
    public const DESCRIPTION = 'RSS Feed für die Rundfunkbeiträge der NAK Süd auf Bayern 2 und SWR 1.
        (Technical note: This bridge might not work on certain server instances because of blacklisted IP ranges to the website.)';
    public const MAINTAINER = 'R3dError';
    public const CACHE_TIMEOUT = 7200;

    private const BAYERN2_ROOT_URI = self::URI . '/mediathek/rundfunksendungen-auf-bayern-2/aktuelle-sendungen';
    private const SWR1_ROOT_URI = self::URI . '/mediathek/rundfunksendungen-auf-swr1/aktuelle-sendungen';

    private const MONTHS = [
        'Januar' => 1,
        'Februar' => 2,
        'März' => 3,
        'April' => 4,
        'Mai' => 5,
        'Juni' => 6,
        'Juli' => 7,
        'August' => 8,
        'September' => 9,
        'Oktober' => 10,
        'November' => 11,
        'Dezember' => 12,
    ];

    public function getIcon(): string
    {
        return self::URI . '/static/themes/sued/images/logo.png';
    }

    private static function parseTimestamp(string $title): ?int
    {
        if (preg_match('/([0-9]+)\.\s*([^\s]+)\s*([0-9]+)/', $title, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $monthName = $matches[2];
        $year = (int) $matches[3];

        if (isset(self::MONTHS[$monthName]) === false) {
            return null;
        }

        $month = self::MONTHS[$monthName];
        $dateString = sprintf('%04d-%02d-%02d', $year, $month, $day);

        return strtotime($dateString) !== false ? strtotime($dateString) : null;
    }

    private static function addTimeToTimestamp(?int $timestamp, string $time): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        $dateString = date('Y-m-d', $timestamp) . ' ' . $time;
        $result = strtotime($dateString);

        return $result !== false ? $result : $timestamp;
    }

    private function getAudioPlayerHtml(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mimeType = match ($ext) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            default => 'audio/mpeg',
        };

        return sprintf(
            '<br><audio controls style="width: 100%%; max-width: 500px; margin-top: 10px;"><source src="%s" type="%s"></audio>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            $mimeType
        );
    }

    private function getBayern2AudioUrl(string $playerUri): ?string
    {
        $cacheKey = 'nac_player_' . md5($playerUri);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $dom = getSimpleHTMLDOM($playerUri);
        $source = $dom->querySelector('source');

        if ($source === null) {
            return null;
        }

        $sourceUri = $source->getAttribute('src');
        if ($sourceUri === null || $sourceUri === '') {
            return null;
        }

        $fullUrl = urljoin(self::URI, $sourceUri);
        $this->cache->set($cacheKey, $fullUrl, 86400);

        return $fullUrl;
    }

    private function collectSwr1Item(\Dom\Element $parent, array $baseItem): array
    {
        $item = $baseItem;

        $links = $parent->querySelectorAll('a');
        if (isset($links[1]) === true) {
            $sourceUri = $links[1]->getAttribute('href');
            if ($sourceUri !== null && $sourceUri !== '') {
                $audioUrl = urljoin(self::URI, $sourceUri);
                $item['enclosures'] = [$audioUrl];
                $item['content'] .= $this->getAudioPlayerHtml($audioUrl);
            }
        }

        $item['timestamp'] = self::addTimeToTimestamp($item['timestamp'] ?? null, '07:27');

        $content = (string) ($item['content'] ?? '');
        if (preg_match('/\(([^)]+)\)/u', html_entity_decode($content, ENT_QUOTES, 'UTF-8'), $matches) === 1) {
            $item['author'] = trim($matches[1]);
        }

        return $item;
    }

    private function collectBayern2Item(\Dom\Element $parent, array $baseItem): array
    {
        $item = $baseItem;

        $firstLink = $parent->querySelector('a');
        if ($firstLink !== null) {
            $onclick = $firstLink->getAttribute('onclick');
            if ($onclick !== null && preg_match('/window\.open\(\'([^\']+)\'/', $onclick, $matches) === 1) {
                $playerUri = urljoin(self::URI, $matches[1]);
                $audioUrl = $this->getBayern2AudioUrl($playerUri);
                if ($audioUrl !== null) {
                    $item['enclosures'] = [$audioUrl];
                    $item['content'] .= $this->getAudioPlayerHtml($audioUrl);
                }
            }
        }

        $item['timestamp'] = self::addTimeToTimestamp($item['timestamp'] ?? null, '06:45');

        return $item;
    }

    private function collectItemsFromPage(string $pageUri, callable $itemCustomizer): void
    {
        $page = getSimpleHTMLDOM($pageUri);

        foreach ($page->querySelectorAll('div.flex-columns.entry') as $parent) {
            $titleNode = $parent->querySelector('h2');
            $title = $titleNode !== null ? trim($titleNode->textContent) : '';

            $contentBlocks = $parent->querySelectorAll('div');
            $contentBlock = $contentBlocks[2] ?? null;

            $content = '';
            if ($contentBlock !== null) {
                foreach ($contentBlock->querySelectorAll('li, p') as $element) {
                    $text = trim($element->textContent);
                    if ($text !== '') {
                        $content .= '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
                    }
                }
            }

            $baseItem = [
                'title' => $title,
                'content' => $content,
                'timestamp' => self::parseTimestamp($title),
            ];

            $this->items[] = $itemCustomizer($parent, $baseItem);
        }
    }

    private function collectFromRoot(string $rootUri, callable $itemCustomizer): void
    {
        $rootPage = getSimpleHTMLDOM($rootUri);
        $filterContainer = $rootPage->querySelector('div.flex-columns.inner_filter');

        if ($filterContainer === null) {
            return;
        }

        foreach ($filterContainer->querySelectorAll('a') as $pageLink) {
            $href = $pageLink->getAttribute('href');
            if ($href === null || $href === '') {
                continue;
            }
            $this->collectItemsFromPage(urljoin($rootUri, $href), $itemCustomizer);
        }
    }

    public function collectData(): void
    {
        $this->collectFromRoot(self::BAYERN2_ROOT_URI, [$this, 'collectBayern2Item']);
        $this->collectFromRoot(self::SWR1_ROOT_URI, [$this, 'collectSwr1Item']);

        usort($this->items, function (array $a, array $b): int {
            $ta = $a['timestamp'] ?? 0;
            $tb = $b['timestamp'] ?? 0;
            return $tb <=> $ta;
        });
    }
}
