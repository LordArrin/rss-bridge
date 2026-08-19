<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

abstract class NginxBase extends BridgeAbstract
{
    const CACHE_TIMEOUT = 3600;

    const PARAMETERS = [
        [
            'source' => [
                'name' => 'Source',
                'type' => 'list',
                'values' => [
                    'Releases' => 'changes',
                    'News' => 'news'
                ],
                'defaultValue' => 'changes'
            ],
            'limit' => [
                'name' => 'Number of entries (max 20)',
                'type' => 'number',
                'defaultValue' => 5
            ]
        ]
    ];

    private const CSS_WRAPPER = 'font-size:14px; line-height:1.6; word-wrap:break-word;';
    private const CSS_SECTION = 'margin:12px 0;';
    private const CSS_HEADING = 'margin:0 0 8px 0; font-size:16px; padding-left:12px; border-left:4px solid %s;';
    private const CSS_UL = 'list-style-type:disc; padding-left:24px;';

    private const COLORS = [
        'Security' => '#cf222e',
        'Feature' => '#1a7f37',
        'Bugfix' => '#0969da',
        'Change' => '#9a6700',
        'Other' => '#6e7781',
    ];

    private const PLURALS = [
        'Change' => 'Changes',
        'Feature' => 'Features',
        'Bugfix' => 'Bugfixes',
    ];

    abstract protected function getSoftwareName(): string;
    abstract protected function getNewsUrl(): string;
    abstract protected function getChangesUrl(): string;
    abstract protected function getTitlePrefix(): string;
    abstract protected function getUidPrefix(): string;

    public function collectData(): void
    {
        $source = $this->getInput('source');
        $limit = (int)$this->getInput('limit');
        if ($limit === 0) {
            $limit = 20;
        }

        match ($source) {
            'changes' => $this->collectChanges(),
            'news' => $this->collectNews(),
            default => throw new \Exception('Invalid source parameter.'),
        };

        usort($this->items, fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
        $this->items = array_slice($this->items, 0, $limit);
    }

    private function collectChanges(): void
    {
        $content = getContents($this->getChangesUrl());
        if ($content === false) {
            throw new \Exception('Failed to load CHANGES');
        }

        $softwareName = $this->getSoftwareName();
        $splitPattern = '/(?=Changes with ' . $softwareName . '\s+)/';
        $blocks = preg_split($splitPattern, $content);

        $headerPattern = '/^Changes with ' . $softwareName . '\s+(\S+)\s+(\d{1,2}\s+\w+\s+\d{4})/';
        $replacePattern = '/^Changes with ' . $softwareName . '\s+\S+\s+\d{1,2}\s+\w+\s+\d{4}\s*/';

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            if (preg_match($headerPattern, $block, $m) === 0) {
                continue;
            }

            $version = $m[1];
            $changes = preg_replace($replacePattern, '', $block);

            $items = $this->parseChangeItems($changes);
            $categories = $this->groupItemsByCategory($items);
            $html = $this->buildCategoriesHtml($categories);

            $this->items[] = [
                'uri' => $this->getChangesUrl(),
                'title' => $this->getTitlePrefix() . ' ' . $version,
                'content' => $html,
                'timestamp' => strtotime($m[2]),
                'uid' => $this->getUidPrefix() . '-' . $version,
            ];
        }
    }

    private function parseChangeItems(string $changes): array
    {
        $lines = explode("\n", $changes);
        $items = [];
        $current = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '*) ') === true) {
                if ($current !== '') {
                    $items[] = $this->normalizeItem($current);
                }
                $current = substr($trimmed, 3);
            } else {
                $current .= ' ' . $trimmed;
            }
        }

        if ($current !== '') {
            $items[] = $this->normalizeItem($current);
        }

        return $items;
    }

    private function normalizeItem(string $item): string
    {
        $item = preg_replace('/Thanks to [^.]+\./', '', $item);
        $item = preg_replace('/\s+/', ' ', trim($item));

        if (preg_match('/^(\w+):\s*(.+)$/', $item, $m) === 1) {
            return $m[1] . ': ' . $this->capitalizeFirst($m[2]);
        }

        return $this->capitalizeFirst($item);
    }

    private function capitalizeFirst(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }

    private function groupItemsByCategory(array $items): array
    {
        $categories = [];
        foreach ($items as $item) {
            if (preg_match('/^(\w+):\s*/', $item, $m) === 1) {
                $categories[$m[1]][] = preg_replace('/^\w+:\s*/', '', $item);
            } else {
                $categories['Other'][] = $item;
            }
        }
        return $categories;
    }

    private function buildCategoriesHtml(array $categories): string
    {
        $html = sprintf('<div style="%s">', self::CSS_WRAPPER);

        foreach ($categories as $categoryName => $items) {
            $color = self::COLORS[$categoryName] ?? self::COLORS['Other'];
            $label = $this->getPluralCategory($categoryName, count($items));

            $html .= sprintf('<div style="%s">', self::CSS_SECTION);
            $html .= sprintf('<h3 style="%s">%s</h3>', sprintf(self::CSS_HEADING, $color), htmlspecialchars($label));
            $html .= sprintf('<ul style="%s">', self::CSS_UL);

            foreach ($items as $item) {
                $html .= '<li>' . htmlspecialchars($item) . '</li>';
            }

            $html .= '</ul></div>';
        }

        return $html . '</div>';
    }

    private function getPluralCategory(string $category, int $count): string
    {
        if ($category === 'Security' || $count === 1) {
            return $category;
        }

        return self::PLURALS[$category] ?? $category . 's';
    }

    private function collectNews(): void
    {
        $html = getSimpleHTMLDOM($this->getNewsUrl());
        if ($html === false) {
            throw new \Exception('Failed to load news page');
        }

        $newsTable = $html->find('table', 0);
        if ($newsTable === false || $newsTable === null) {
            throw new \Exception('News table not found');
        }

        foreach ($newsTable->find('tr') as $row) {
            $cells = $row->find('td');
            if (count($cells) < 2) {
                continue;
            }

            $dateText = trim($cells[0]->plaintext);
            $content = trim($cells[1]->innertext);

            if ($dateText === '' || $content === '') {
                continue;
            }

            $dom = str_get_html($content);
            if ($dom !== false) {
                $dom = defaultLinkTo($dom, $this->getURI());
                $content = $dom->save();
            }

            $this->items[] = [
                'uri' => $this->getNewsUrl(),
                'title' => 'Update ' . $dateText,
                'content' => sprintf('<div style="%s">%s</div>', self::CSS_WRAPPER, $content),
                'timestamp' => strtotime($dateText),
                'uid' => $this->getUidPrefix() . '-news-' . $dateText,
            ];
        }
    }
}
