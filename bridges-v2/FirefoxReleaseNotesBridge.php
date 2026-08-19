<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class FirefoxReleaseNotesBridge extends BridgeAbstract
{
    const NAME = 'Firefox Release Notes';
    const URI = 'https://www.firefox.com/en-US/releases/';
    const DESCRIPTION = 'Returns recent Firefox releases with changelogs for each version';
    const MAINTAINER = 'LordArrin';
    const PARAMETERS = [];

    const RELEASE_LIMIT = 8;
    const RELEASE_NOTES_CACHE_TTL = 604800;
    const RELEASES_LIST_CACHE_TTL = 86400;

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
        $html = getSimpleHTMLDOMCached(self::URI, self::RELEASES_LIST_CACHE_TTL);
        if (!$html) {
            throwClientException('Failed to load Firefox releases page.');
        }

        $html = defaultLinkTo($html, self::URI);
        $links = $html->find('a[href*="/releasenotes/"]') ?: $html->find('a[href*="/firefox/"]');

        $releases = [];
        foreach ($links as $link) {
            $text = trim($link->plaintext);
            if (preg_match(self::VERSION_PATTERN, $text, $matches)) {
                $releases[] = ['version' => $matches[1], 'url' => $link->href];
            }
        }

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

        $notesHtml = getSimpleHTMLDOMCached($release['url'], self::RELEASE_NOTES_CACHE_TTL);
        if (!$notesHtml) {
            $item['content'] = '<p>Failed to load release notes page.</p>';
            return $item;
        }

        $notesHtml = defaultLinkTo($notesHtml, $release['url']);

        $timestamp = $this->extractReleaseDate($notesHtml);
        if ($timestamp !== null) {
            $item['timestamp'] = $timestamp;
        }

        $item['content'] = $this->buildReleaseContent($notesHtml);

        return $item;
    }

    private function extractReleaseDate(object $html): ?int
    {
        $dateElement = $html->find('.c-release-date', 0);
        if ($dateElement) {
            $dateText = trim($dateElement->plaintext);
            $timestamp = strtotime($dateText);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $timeTag = $html->find('time', 0);
        if ($timeTag) {
            $dateText = $timeTag->datetime ?: $timeTag->plaintext;
            $timestamp = strtotime($dateText);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $pageText = $html->plaintext;
        foreach (self::DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $pageText, $matches)) {
                $timestamp = strtotime($matches[1]);
                if ($timestamp !== false) {
                    return $timestamp;
                }
            }
        }

        return null;
    }

    private function buildReleaseContent(object $html): string
    {
        $parts = [];

        $firstText = $html->find('.c-release-first-text', 0);
        if ($firstText && trim($firstText->plaintext)) {
            $parts[] = $this->cleanHtml($firstText->innertext);
        }

        $notesBlock = $html->find('section.c-release-notes', 0);
        if (!$notesBlock) {
            return implode("\n", $parts);
        }

        foreach ($notesBlock->find('div[id]') as $div) {
            $sectionId = $div->id;
            if (in_array($sectionId, self::EXCLUDED_SECTIONS, true)) {
                continue;
            }

            $heading = $div->find('.fl-c-release-notes-heading', 0);
            if (!$heading) {
                continue;
            }

            $title = trim($heading->plaintext);
            if ($title === '') {
                continue;
            }

            $main = $div->find('.mzp-l-main', 0);
            if (!$main) {
                continue;
            }

            $items = [];
            foreach ($main->find('li.release-note') as $note) {
                $content = $note->find('.release-note-content', 0);
                if (!$content) {
                    continue;
                }

                $cleaned = $this->cleanHtml($content->innertext);
                if ($cleaned !== '') {
                    $items[] = $cleaned;
                }
            }

            if (empty($items)) {
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
        $dom = str_get_html($html);
        if (!$dom) {
            return '';
        }

        foreach ($dom->find(implode(',', self::JUNK_SELECTORS)) as $junk) {
            $junk->outertext = '';
        }

        foreach ($dom->find('img') as $img) {
            $img->width = null;
            $img->height = null;
            $img->setAttribute('style', 'max-width:100%;height:auto;');
        }

        return trim((string) $dom);
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
