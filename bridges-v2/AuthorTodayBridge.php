<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class AuthorTodayBridge extends BridgeAbstract
{
    public const NAME = 'Author Today';
    public const URI = 'https://author.today';
    public const DESCRIPTION = 'Returns updates for stories by chapter';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 900;

    public const PARAMETERS = [
        '' => [
            'work' => [
                'name' => 'Story ID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => '230933',
            ],
            'notags' => [
                'name' => 'Disable tags',
                'type' => 'checkbox',
                'required' => false,
                'defaultValue' => 'checked',
            ],
        ],
    ];

    public const CSS = [
        'status' => 'margin-bottom:10px',
        'label' => 'display:inline-block;padding:2px 6px;border-radius:3px;color:#ffffff;white-space:nowrap',
        'label_primary' => 'background:#337ab7',
        'label_success' => 'background:#5cb85c',
        'label_danger' => 'background:#d9534f',
        'label_default' => 'background:#777777',
        'meta' => 'white-space:nowrap',
        'separator' => 'color:#999999',
        'cover' => 'display:block;max-width:300px;height:auto;border-radius:4px',
    ];

    private const ITEM_LIMIT = 5;
    private const FOOTER_SEARCH_DEPTH = 8;

    private string $feedTitle = '';

    public function getURI(): string
    {
        $workId = $this->workId();

        if ($workId === null) {
            return self::URI;
        }

        return self::URI . '/work/' . $workId;
    }

    public function getName(): string
    {
        return $this->feedTitle !== '' ? $this->feedTitle : self::NAME;
    }

    public function getIcon(): string
    {
        return self::URI . '/favicon.ico';
    }

    public function collectData(): void
    {
        $workId = $this->workId();

        if ($workId === null) {
            throwClientException('Story ID must be a number or a URL containing /work/{id}');
        }

        $html = $this->loadPage($workId);
        $this->feedTitle = $this->extractFeedTitle($html);
        $items = $this->parseChapters($html);

        if ($items === []) {
            throwServerException('Unable to parse chapters.');
        }

        $this->addSortedItems($items);
    }

    private function loadPage(string $workId): \Dom\HTMLDocument
    {
        $url = self::URI . '/work/' . $workId;
        $html = getContents($url);

        if (empty($html)) {
            throwServerException("Unable to load page: {$url}");
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }

    private function extractFeedTitle(\Dom\HTMLDocument $html): string
    {
        $titleNode = $html->querySelector('h1.book-title');

        if ($titleNode === null) {
            return '';
        }

        return trim($titleNode->textContent);
    }

    private function parseChapters(\Dom\HTMLDocument $html): array
    {
        $authorNode = $html->querySelector('.book-authors a');
        $author = $authorNode !== null ? trim($authorNode->textContent) : '';

        $coverNode = $html->querySelector('img.cover-image');
        $coverUrl = $coverNode !== null ? $this->absoluteUrl((string)$coverNode->getAttribute('src')) : '';

        $statusHtml = $this->statusHtml($html);
        $tags = $this->getInput('notags') === true ? [] : $this->tags($html);

        $chapters = $html->querySelectorAll('#tab-chapters ul.table-of-content li');

        if ($chapters->length === 0) {
            throwServerException('Chapter list not found. The work may be unavailable or markup has changed.');
        }

        $items = [];
        $chaptersArray = iterator_to_array($chapters);

        foreach (array_reverse($chaptersArray) as $position => $chapter) {
            $item = $this->buildChapterItem($chapter, $position, $statusHtml, $coverUrl, $author, $tags);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function buildChapterItem(
        \Dom\Element $chapter,
        int $position,
        string $statusHtml,
        string $coverUrl,
        string $author,
        array $tags
    ): ?array {
        $link = $chapter->querySelector('a');

        if ($link === null) {
            return null;
        }

        $title = trim($link->textContent);
        $uri = $this->absoluteUrl((string)$link->getAttribute('href'));
        $timeNode = $chapter->querySelector('[data-time]');
        $timestamp = $timeNode !== null ? $this->timestamp((string)$timeNode->getAttribute('data-time')) : null;

        $content = $statusHtml;

        if ($coverUrl !== '') {
            $escapedUri = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
            $escapedCover = htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8');
            $coverStyle = self::CSS['cover'];
            $content .= "<p><a href=\"{$escapedUri}\"><img src=\"{$escapedCover}\" alt=\"Cover\" style=\"{$coverStyle}\"></a></p>";
        }

        return [
            'uri' => $uri,
            'title' => $title !== '' ? $title : 'Chapter',
            'uid' => $uri,
            'content' => $content,
            '_position' => $position,
            ...($timestamp !== null ? ['timestamp' => $timestamp] : []),
            ...($author !== '' ? ['author' => $author] : []),
            ...($tags !== [] ? ['categories' => $tags] : []),
        ];
    }

    private function addSortedItems(array $items): void
    {
        usort($items, function (array $a, array $b): int {
            $timeComparison = ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);

            return $timeComparison !== 0 ? $timeComparison : $a['_position'] <=> $b['_position'];
        });

        foreach (array_slice($items, 0, self::ITEM_LIMIT) as $item) {
            unset($item['_position']);
            $this->items[] = $item;
        }
    }

    private function workId(): ?string
    {
        $value = trim((string)$this->getInput('work'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value) === true) {
            return $value;
        }

        if (preg_match(pattern: '#^(\d+)/?$#', subject: $value, matches: $matches) === 1) {
            return $matches[1];
        }

        if (preg_match(pattern: '#/work/(\d+)#', subject: $value, matches: $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function absoluteUrl(string $url): string
    {
        return $url
            |> trim(...)
            |> (fn($u) => $u === '' || preg_match(pattern: '#^https?://#i', subject: $u) === 1 ? ($u !== '' ? $u : self::URI) : self::URI . '/' . ltrim($u, '/'))(...);
    }

    private function timestamp(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = (string)preg_replace(pattern: '/\.\d+/', replacement: '', subject: $value);

        if (preg_match(pattern: '/(Z|[+-]\d{2}:?\d{2})$/', subject: $value) !== 1) {
            $value .= 'Z';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value);

        if ($date === false) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value);
        }

        if ($date === false) {
            return null;
        }

        return $date->getTimestamp();
    }

    private function plainText(\Dom\Node $node): string
    {
        $innerHtml = '';
        foreach ($node->childNodes as $child) {
            $innerHtml .= $node->ownerDocument->saveHTML($child);
        }

        $text = html_entity_decode(strip_tags($innerHtml), ENT_QUOTES, 'UTF-8');

        return trim((string)preg_replace(pattern: '/\s+/u', replacement: ' ', subject: $text));
    }

    private function tags(\Dom\HTMLDocument $html): array
    {
        $tags = [];

        foreach ($html->querySelectorAll('.mb-v-lg .tags a') as $node) {
            $tag = $this->plainText($node);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    private function statusIcon(string $class): string
    {
        return match (true) {
            str_contains($class, 'icon-pencil') => '&#9998;',
            str_contains($class, 'icon-check') => '&#10004;',
            default => '&#8226;',
        };
    }

    private function labelStyle(string $class): string
    {
        $color = match (true) {
            str_contains($class, 'label-success') => self::CSS['label_success'],
            str_contains($class, 'label-primary') => self::CSS['label_primary'],
            str_contains($class, 'label-danger') => self::CSS['label_danger'],
            default => self::CSS['label_default'],
        };

        return self::CSS['label'] . ';' . $color;
    }

    private function isInsideFooter(\Dom\Node $node): bool
    {
        $current = $node;
        for ($i = 0; $i < self::FOOTER_SEARCH_DEPTH && $current !== null; $i++) {
            if ($current instanceof \Dom\Element && strtolower($current->tagName) === 'footer') {
                return true;
            }

            $current = $current->parentNode;
        }

        return false;
    }

    private function adultText(\Dom\HTMLDocument $html): string
    {
        foreach ($html->querySelectorAll('.label-adult-only') as $node) {
            if ($this->isInsideFooter($node) === true) {
                continue;
            }

            $text = $this->plainText($node);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function likeCount(\Dom\HTMLDocument $html): string
    {
        $source = $html->saveHTML();

        if (preg_match(pattern: '/likeCount["\']?\s*:\s*["\']?(\d+)/i', subject: $source, matches: $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function statusHtml(\Dom\HTMLDocument $html): string
    {
        $label = $html->querySelector('.book-meta-panel .label');
        $time = $html->querySelector('.book-meta-panel [data-format="calendar-short"]');

        if ($label === null && $time === null) {
            return '';
        }

        $parts = [];
        $labelText = $label !== null ? $this->plainText($label) : '';

        if ($labelText !== '') {
            $parts[] = $this->buildLabelSpan($label, $labelText);
        }

        $adultText = $this->adultText($html);

        if ($adultText !== '') {
            $labelStyle = $this->labelStyle('label-danger');
            $escapedAdult = htmlspecialchars($adultText, ENT_QUOTES, 'UTF-8');
            $parts[] = "<span style=\"{$labelStyle}\">{$escapedAdult}</span>";
        }

        $likes = $this->likeCount($html);

        if ($likes !== '') {
            $metaStyle = self::CSS['meta'];
            $escapedLikes = htmlspecialchars($likes, ENT_QUOTES, 'UTF-8');
            $parts[] = "<span style=\"{$metaStyle}\">&#9829;&#160;{$escapedLikes}</span>";
        }

        if ($time !== null) {
            $timeSpan = $this->buildTimeSpan($time);

            if ($timeSpan !== '') {
                $parts[] = $timeSpan;
            }
        }

        $sizeText = $this->extractSizeText($label, $labelText, $adultText);

        if ($sizeText !== '') {
            $escapedSize = htmlspecialchars($sizeText, ENT_QUOTES, 'UTF-8');
            $parts[] = "<span>{$escapedSize}</span>";
        }

        if ($parts === []) {
            return '';
        }

        $separatorStyle = self::CSS['separator'];
        $separator = "<span style=\"{$separatorStyle}\">&#160;|&#160;</span>";

        $statusStyle = self::CSS['status'];

        return "<div style=\"{$statusStyle}\">" . implode($separator, $parts) . '</div>';
    }

    private function buildLabelSpan(\Dom\Element $label, string $labelText): string
    {
        $iconNode = $label->querySelector('i');
        $iconClass = $iconNode !== null ? (string)$iconNode->getAttribute('class') : '';
        $labelClass = (string)$label->getAttribute('class');

        $labelStyle = $this->labelStyle($labelClass);
        $statusIcon = $this->statusIcon($iconClass);
        $escapedLabel = htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8');

        return "<span style=\"{$labelStyle}\">{$statusIcon}&#160;{$escapedLabel}</span>";
    }

    private function buildTimeSpan(\Dom\Element $time): string
    {
        $timestamp = $this->timestamp((string)$time->getAttribute('data-time'));

        if ($timestamp === null) {
            return '';
        }

        $formattedDate = htmlspecialchars(date('d.m.Y H:i', $timestamp), ENT_QUOTES, 'UTF-8');

        return "<span>{$formattedDate}</span>";
    }

    private function extractSizeText(?\Dom\Element $label, string $labelText, string $adultText): string
    {
        $statusNode = $label !== null ? $label->parentElement : null;

        if ($statusNode === null) {
            return '';
        }

        $sizeText = $this->plainText($statusNode);

        if ($sizeText !== '' && $labelText !== '') {
            $sizeText = trim(str_replace($labelText, '', $sizeText), " \t\n\r\0\x0B|");
        }

        if ($sizeText !== '' && $adultText !== '') {
            $sizeText = trim(str_replace($adultText, '', $sizeText), " \t\n\r\0\x0B|");
        }

        return $sizeText;
    }
}
