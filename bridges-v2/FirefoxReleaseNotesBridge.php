<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class FirefoxReleaseNotesBridge extends BridgeAbstract
{
    public const NAME = 'Firefox Release Notes';
    public const URI = 'https://www.firefox.com/en-US/releases/';
    public const DESCRIPTION = 'Returns recent Firefox releases with changelogs for each version';
    public const MAINTAINER = 'LordArrin';
    public const PARAMETERS = [];

    public const RELEASE_LIMIT = 8;
    public const RELEASE_NOTES_CACHE_TTL = 604800;
    public const RELEASES_LIST_CACHE_TTL = 86400;

    private const DATE_PATTERNS = [
        '/([A-Z][a-z]+ \d{1,2}, \d{4})/',
        '/(\d{1,2} [A-Z][a-z]+ \d{4})/',
        '/(\d{4}-\d{2}-\d{2})/',
    ];

    private const VERSION_PATTERN = '/(\d+(\.\d+)+)/';

    private const JUNK_SELECTORS = [
        'script',
        'style',
        'noscript',
        'iframe',
        '.fl-icon',
        '.release-note-progressive-rollout-indicator',
    ];

    private const EXCLUDED_SECTIONS = [
        'community',
    ];

    private const SECTION_COLORS = [
        'new'        => '#7542E5',
        'fixed'      => '#FF9400',
        'changed'    => '#E31587',
        'developer'  => '#3F2596',
        'html5'      => '#9059FF',
        'labs'       => '#FF7139',
        'known'      => '#C45A00',
        'security'   => '#D70022',
        'enterprise' => '#4A3428',
    ];

    private const FALLBACK_SECTION_COLOR = '#592ACB';

    public function collectData(): void
    {
        $releases = $this->prepareReleases();

        foreach ($releases as $release) {
            $this->items[] = $this->buildReleaseItem($release);
        }

        $this->sortItemsByDate();
    }

    private function fetchReleaseLinks(): array
    {
        $cacheKey = 'firefox_releases_list';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $html = getContents(self::URI);
        if ($html === '' || $html === null) {
            throwClientException('Failed to load Firefox releases page.');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $links = $dom->querySelectorAll('a[href*="/releasenotes/"]');
        if ($links->length === 0) {
            $links = $dom->querySelectorAll('a[href*="/firefox/"]');
        }

        $releases = [];
        foreach ($links as $link) {
            $text = trim($link->textContent);
            $href = (string) $link->getAttribute('href');
            if (preg_match(self::VERSION_PATTERN, $text, $matches) === 1) {
                $releases[] = [
                    'version' => $matches[1],
                    'url' => urljoin(self::URI, $href)
                ];
            }
        }

        $this->cache->set($cacheKey, $releases, self::RELEASES_LIST_CACHE_TTL);

        return $releases;
    }

    private function prepareReleases(): array
    {
        $releases = $this->fetchReleaseLinks();

        $unique = [];
        foreach ($releases as $release) {
            $unique[$release['url']] = $release;
        }

        return array_slice(array_values($unique), 0, self::RELEASE_LIMIT);
    }

    private function buildReleaseItem(array $release): array
    {
        $item = [
            'title' => 'Firefox ' . $release['version'],
            'uri' => $release['url'],
            'uid' => $release['url'],
        ];

        $cacheKey = 'firefox_release_notes_' . md5($release['url']);
        $cachedHtml = $this->cache->get($cacheKey);

        if ($cachedHtml === null) {
            try {
                $html = getContents($release['url']);
                if (($html ?? '') !== '' && $html !== null) {
                    $this->cache->set($cacheKey, $html, self::RELEASE_NOTES_CACHE_TTL);
                    $cachedHtml = $html;
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($cachedHtml === '' || $cachedHtml === null) {
            $item['content'] = '<p>Failed to load release notes page.</p>';
            return $item;
        }

        libxml_use_internal_errors(true);
        $notesHtml = \Dom\HTMLDocument::createFromString($cachedHtml);
        libxml_use_internal_errors(false);

        $timestamp = $this->extractReleaseDate($notesHtml);
        if ($timestamp !== null) {
            $item['timestamp'] = $timestamp;
        }

        $item['content'] = $this->buildReleaseContent($notesHtml);

        return $item;
    }

    private function extractReleaseDate(\Dom\HTMLDocument $html): ?int
    {
        $dateElement = $html->querySelector('.c-release-date');
        if ($dateElement !== null) {
            $dateText = trim($dateElement->textContent);
            $timestamp = strtotime($dateText);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $timeTag = $html->querySelector('time');
        if ($timeTag !== null) {
            $datetime = $timeTag->getAttribute('datetime');
            $dateText = ($datetime !== null && $datetime !== '') ? $datetime : $timeTag->textContent;
            $timestamp = strtotime($dateText);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $pageText = $html->documentElement->textContent ?? '';
        foreach (self::DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $pageText, $matches) === 1) {
                $timestamp = strtotime($matches[1]);
                if ($timestamp !== false) {
                    return $timestamp;
                }
            }
        }

        return null;
    }

    private function buildReleaseContent(\Dom\HTMLDocument $html): string
    {
        $parts = [];

        $firstText = $html->querySelector('.c-release-first-text');
        if ($firstText !== null && trim($firstText->textContent) !== '') {
            $parts[] = $this->cleanHtml($firstText->innerHTML);
        }

        $notesBlock = $html->querySelector('section.c-release-notes');
        if ($notesBlock === null) {
            return implode("\n", $parts);
        }

        foreach ($notesBlock->querySelectorAll('div[id]') as $div) {
            $sectionId = $div->getAttribute('id') ?? '';
            if (in_array($sectionId, self::EXCLUDED_SECTIONS, true) === true) {
                continue;
            }

            $heading = $div->querySelector('.fl-c-release-notes-heading');
            if ($heading === null) {
                continue;
            }

            $title = trim($heading->textContent);
            if ($title === '') {
                continue;
            }

            $main = $div->querySelector('.mzp-l-main');
            if ($main === null) {
                continue;
            }

            $items = [];
            foreach ($main->querySelectorAll('li.release-note') as $note) {
                $content = $note->querySelector('.release-note-content');
                if ($content === null) {
                    continue;
                }

                $cleaned = $this->cleanHtml($content->innerHTML);
                if ($cleaned !== '') {
                    $items[] = $cleaned;
                }
            }

            if ($items === []) {
                continue;
            }

            $parts[] = $this->buildSection($sectionId, $title, $items);
        }

        return implode("\n", $parts);
    }

    private function buildSection(string $sectionId, string $title, array $items): string
    {
        $color = self::SECTION_COLORS[$sectionId] ?? self::FALLBACK_SECTION_COLOR;

        $headingStyle = sprintf(
            'font-size:1.3em;font-weight:600;margin:1.5em 0 0.5em;padding:0.3em 0.6em;border-left:4px solid %s;color:%s;',
            $color,
            $color
        );

        $html = '<h3 style="' . $headingStyle . '">' . htmlspecialchars($title) . '</h3>';
        $html .= '<ul style="list-style-type:disc;margin:0.5em 0;padding-left:1.5em;">';

        foreach ($items as $item) {
            $html .= '<li style="margin:0.5em 0;">' . $item . '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    private function cleanHtml(string $html): string
    {
        if ($html === '' || $html === null) {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
        libxml_use_internal_errors(false);

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return $html;
        }

        foreach ($wrapper->querySelectorAll(implode(',', self::JUNK_SELECTORS)) as $junk) {
            $junk->remove();
        }

        foreach ($wrapper->querySelectorAll('img') as $img) {
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->setAttribute('style', 'max-width:100%;height:auto;');
        }

        return trim($wrapper->innerHTML);
    }

    private function sortItemsByDate(): void
    {
        usort($this->items, function ($a, $b): int {
            $timeA = $a['timestamp'] ?? 0;
            $timeB = $b['timestamp'] ?? 0;
            return $timeB <=> $timeA;
        });
    }
}
