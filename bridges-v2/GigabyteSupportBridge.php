<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class GigabyteSupportBridge extends BridgeAbstract
{
    public const NAME = 'Gigabyte Support';
    public const URI = 'https://www.gigabyte.com/';
    public const DESCRIPTION = 'Returns BIOS and drivers updates for Gigabyte products';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 14400;
    public const VALID_TYPES = ['driver', 'bios'];

    public const PARAMETERS = [
        '' => [
            'url' => [
                'name' => 'Support page URL',
                'type' => 'text',
                'required' => true,
                'title' => 'Full URL of the product support page on gigabyte.com (hash fragments like #Support-Bios or #Support-Driver are supported)'
            ],
            'hide_download_button' => [
                'name' => 'Hide download button',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Hide the download button from feed items'
            ]
        ]
    ];

    private const CSS = [
        'item' => 'font-family:sans-serif;line-height:1.6;color:inherit',
        'p' => 'margin:8px 0',
        'link' => 'color:#ff6600;text-decoration:none;font-weight:500',
        'label' => 'font-weight:bold;color:inherit',
        'download' => 'display:inline-block;margin-top:10px;padding:8px 16px;background:#ff6600;color:#fff;text-decoration:none;border-radius:4px;font-weight:500',
    ];

    private ?array $productInfo = null;
    private ?\Dom\HTMLDocument $pageDom = null;

    public function getIcon(): string
    {
        return self::URI . 'favicon.ico';
    }

    public function getName(): string
    {
        $info = $this->getProductInfo();
        if ($info === null) {
            return parent::getName();
        }

        $productName = $this->extractProductName();
        $name = $productName ?? str_replace('-', ' ', $info['product']);

        $fragment = strtolower(preg_replace('/^support-/i', '', $info['fragment']) ?? '');
        if (in_array($fragment, self::VALID_TYPES, true) === true) {
            $name .= ' (' . ($fragment === 'bios' ? 'BIOS' : ucfirst($fragment)) . ')';
        }

        return $name;
    }

    public function getURI(): string
    {
        $url = $this->getInput('url');
        return $url !== null ? (string)$url : parent::getURI();
    }

    private function getProductInfo(): ?array
    {
        if ($this->productInfo !== null) {
            return $this->productInfo;
        }

        $url = $this->getInput('url');
        if ($url === null || is_string($url) === false) {
            return null;
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || isset($parsedUrl['path']) === false) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', trim($parsedUrl['path'], '/')),
            fn(string $s): bool => $s !== ''
        ));

        if (count($segments) < 2) {
            return null;
        }

        if (end($segments) === 'support') {
            array_pop($segments);
        }

        $this->productInfo = [
            'product' => array_pop($segments),
            'category' => array_pop($segments),
            'fragment' => $parsedUrl['fragment'] ?? ''
        ];

        return $this->productInfo;
    }

    private function supportUrl(array $info): string
    {
        return sprintf('https://www.gigabyte.com/%s/%s/support', $info['category'], $info['product']);
    }

    private function getDownloadTypes(): array
    {
        $info = $this->getProductInfo();
        if ($info === null) {
            return self::VALID_TYPES;
        }

        $fragment = strtolower(preg_replace('/^support-/i', '', $info['fragment']) ?? '');
        return in_array($fragment, self::VALID_TYPES, true) === true ? [$fragment] : self::VALID_TYPES;
    }

    private function fetchPageDom(): ?\Dom\HTMLDocument
    {
        if ($this->pageDom !== null) {
            return $this->pageDom;
        }

        $info = $this->getProductInfo();
        if ($info === null) {
            return null;
        }

        try {
            $html = getContents($this->supportUrl($info));

            if ($html === '' || $html === null) {
                return null;
            }

            if (class_exists('\\tidy') === true && str_contains($html, '</html>') === false) {
                $tidy = new \tidy();
                $tidy->parseString($html, [
                    'clean' => true,
                    'output-xhtml' => true,
                    'wrap' => 0,
                    'drop-empty-elements' => false,
                ], 'utf8');
                $tidy->cleanRepair();
                $html = (string)$tidy;
            }

            $this->pageDom = \Dom\HTMLDocument::createFromString($html);
            return $this->pageDom;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractProductName(): ?string
    {
        $dom = $this->fetchPageDom();
        if ($dom === null) {
            return null;
        }

        $selectors = [
            '.model-base-info-title',
            '[class*="model-base-info-title"]',
            'h1.title',
            'h1',
        ];

        foreach ($selectors as $selector) {
            $node = $dom->querySelector($selector);
            $name = $node?->textContent;
            if ($name !== null && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    private function normalize(string $text, bool $keepLinks = false): string
    {
        if ($text === '') {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/Checksum\s*:\s*\S+/i', '', $text) ?? $text;

        if ($keepLinks === true) {
            $text = preg_replace('/<li[^>]*>/i', '[[SEP]]', $text) ?? $text;
            $text = preg_replace('/<\/li>/i', '', $text) ?? $text;
            $text = preg_replace('/<br\s*\/?>/i', '[[SEP]]', $text) ?? $text;
            $text = preg_replace('/<\/?p[^>]*>/i', '[[SEP]]', $text) ?? $text;
            $text = preg_replace('/<\/?div[^>]*>/i', '[[SEP]]', $text) ?? $text;
            $text = preg_replace('/<ol[^>]*>/i', '[[SEP]]', $text) ?? $text;
            $text = preg_replace('/<\/ol>/i', '', $text) ?? $text;
            $text = preg_replace('/<ul[^>]*>/i', '[[SEP]]', $text) ?? $text;
            $text = preg_replace('/<\/ul>/i', '', $text) ?? $text;

            $linkStyle = self::CSS['link'];
            $text = preg_replace_callback('/<a\s+([^>]*?)>(.*?)<\/a>/is', function (array $m) use ($linkStyle): string {
                $attrs = $m[1];

                if (str_contains(strtolower($attrs), 'target=') === false) {
                    $attrs .= ' target="_blank"';
                }
                if (str_contains(strtolower($attrs), 'rel=') === false) {
                    $attrs .= ' rel="noopener noreferrer"';
                }
                if (str_contains(strtolower($attrs), 'style=') === false) {
                    $attrs .= ' style="' . $linkStyle . '"';
                }

                return '<a ' . trim($attrs) . '>' . $m[2] . '</a>';
            }, $text) ?? $text;

            $text = strip_tags($text, '<a>');
            $parts = preg_split('/\[\[SEP\]\]|\n|\r\n?/', $text);
            if ($parts === false) {
                $parts = [$text];
            }

            $cleanParts = array_filter(
                array_map(fn(string $part): string => trim(preg_replace('/\s+/', ' ', $part) ?? ''), $parts),
                fn(string $part): bool => $part !== ''
            );

            return implode('<br>', $cleanParts);
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace('/<br\s*\/?>/i', ', ', $text) ?? $text;
        $text = preg_replace('/(64bit|32bit)\s+(Windows|Linux|macOS)/i', '$1, $2', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/,\s*,/', ',', $text) ?? $text;

        return trim($text, ', ');
    }

    private function parseSections(\Dom\HTMLDocument $dom): array
    {
        $items = [];

        $h2Nodes = $dom->querySelectorAll('h2');

        $h2Positions = [];
        foreach ($h2Nodes as $h2Node) {
            $category = trim($h2Node->textContent ?? '');
            if ($category !== '') {
                $h2Positions[] = [
                    'node' => $h2Node,
                    'category' => $category
                ];
            }
        }

        $tables = $dom->querySelectorAll('table');

        foreach ($tables as $table) {
            $category = '';

            foreach ($h2Positions as $h2Info) {
                $h2Node = $h2Info['node'];

                if ($this->isBefore($h2Node, $table) === true) {
                    $category = $h2Info['category'];
                } else {
                    break;
                }
            }

            if ($category === '') {
                continue;
            }

            $type = strtolower($category) === 'bios' ? 'bios' : 'driver';

            $rows = $table->querySelectorAll('tr');

            $hasOs = null;
            foreach ($rows as $row) {
                if ($row->querySelector('th') !== null) {
                    continue;
                }
                $cells = $row->querySelectorAll('td');
                if ($cells->length >= 4) {
                    $hasOs = $cells->length >= 6;
                    break;
                }
            }

            if ($hasOs === null) {
                continue;
            }

            foreach ($rows as $row) {
                if ($row->querySelector('th') !== null) {
                    continue;
                }

                $cells = $row->querySelectorAll('td');
                if ($cells->length < 4) {
                    continue;
                }

                $descriptionNode = $cells->item(0);
                $versionNode = $cells->item(1);
                $osNode = $hasOs === true ? $cells->item(2) : null;
                $sizeNode = $cells->item($hasOs === true ? 3 : 2);
                $dateNode = $cells->item($hasOs === true ? 4 : 3);

                if ($descriptionNode === null || $versionNode === null) {
                    continue;
                }

                $downloadUrl = '';
                $links = $row->querySelectorAll('a');
                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    if ($href !== null && (str_contains($href, 'download.gigabyte.com') === true || str_contains($href, '.zip') === true)) {
                        $downloadUrl = $href;
                        break;
                    }
                }

                if ($downloadUrl !== '' && str_starts_with($downloadUrl, '/') === true) {
                    $downloadUrl = 'https://www.gigabyte.com' . $downloadUrl;
                }

                $items[] = [
                    'type' => $type,
                    'category' => $category,
                    'description' => $this->normalize($descriptionNode->innerHTML ?? '', true),
                    'version' => $this->normalize($versionNode->textContent ?? ''),
                    'os' => $osNode !== null ? $this->normalize($osNode->textContent ?? '') : '',
                    'size' => $this->normalize($sizeNode?->textContent ?? ''),
                    'date' => $this->normalize($dateNode?->textContent ?? ''),
                    'download' => $downloadUrl
                ];
            }
        }

        return $items;
    }

    private function isBefore(\Dom\Element $node1, \Dom\Element $node2): bool
    {
        $pos1 = $this->getDocumentPosition($node1);
        $pos2 = $this->getDocumentPosition($node2);
        return $pos1 < $pos2;
    }

    private function getDocumentPosition(\Dom\Element $node): int
    {
        $position = 0;
        $current = $node;

        while ($current !== null && $current->parentElement !== null) {
            $parent = $current->parentElement;
            $children = $parent->children;

            foreach ($children as $index => $child) {
                if ($child === $current) {
                    break;
                }
                $position += $this->getNodeWeight($child);
            }

            $current = $parent;
        }

        return $position;
    }

    private function getNodeWeight(\Dom\Element $node): int
    {
        $weight = 1;
        foreach ($node->children as $child) {
            $weight += $this->getNodeWeight($child);
        }
        return $weight;
    }

    private function render(string $label, string $value, bool $lineBreak = false, bool $allowHtml = false): string
    {
        if ($value === '') {
            return '';
        }

        $displayValue = $allowHtml === true ? $value : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $displayValue = ltrim(preg_replace('/^(?:\s|<br\s*\/?>)+/i', '', $displayValue) ?? $displayValue);

        if ($displayValue === '') {
            return '';
        }

        $separator = $lineBreak === true ? '<br>' : ' ';
        return sprintf(
            '<p style="%s"><span style="%s">%s:</span>%s%s</p>',
            self::CSS['p'],
            self::CSS['label'],
            $label,
            $separator,
            $displayValue
        );
    }

    private function buildFeedItem(array $data, array $info, bool $hideAttachments): array
    {
        if ($data['type'] === 'bios') {
            $itemTitle = sprintf('[%s] %s', $data['category'], $data['version']);
        } else {
            $rawTitle = sprintf('[%s] %s', $data['category'], strip_tags($data['description']));
            $rawTitle = preg_replace('/Checksum\s*:\s*\S+/i', '', $rawTitle) ?? $rawTitle;
            $itemTitle = trim(preg_replace('/\s+/', ' ', $rawTitle) ?? $rawTitle);

            if (($data['version'] ?? '') !== '') {
                $itemTitle .= ' - ' . $data['version'];
            }
        }

        $uri = $this->supportUrl($info);
        if ($info['fragment'] !== '') {
            $uri .= '#' . $info['fragment'];
        }

        $content = '<div style="' . self::CSS['item'] . '">';
        $content .= $this->render('Description', $data['description'], true, true);
        $content .= $this->render('OS', $data['os']);
        $content .= $this->render('Size', $data['size']);

        if ($hideAttachments === false && ($data['download'] ?? '') !== '') {
            $downloadUrl = htmlspecialchars($data['download'], ENT_QUOTES, 'UTF-8');
            $content .= sprintf(
                '<p style="%s"><a href="%s" style="%s" target="_blank" rel="noopener noreferrer">Download</a></p>',
                self::CSS['p'],
                $downloadUrl,
                self::CSS['download']
            );
        }

        $content .= '</div>';

        $item = [
            'title' => $itemTitle,
            'uri' => $uri,
            'content' => $content,
            'uid' => md5($itemTitle . $data['version'])
        ];

        if (($data['date'] ?? '') !== '') {
            $timestamp = strtotime($data['date']);
            if ($timestamp !== false && $timestamp > 0 && $timestamp < time() + 86400 * 365) {
                $item['timestamp'] = $timestamp;
            }
        }

        return $item;
    }

    public function collectData(): void
    {
        $info = $this->getProductInfo();
        if ($info === null || $info['product'] === '' || $info['category'] === '') {
            throwClientException('Invalid URL format. Expected: https://www.gigabyte.com/Category/Product-ID/support');
        }

        $dom = $this->fetchPageDom();
        if ($dom === null) {
            throwServerException('Failed to fetch support page content');
        }

        $hideAttachments = (bool)$this->getInput('hide_download_button');
        $types = $this->getDownloadTypes();
        $allItems = [];

        foreach ($this->parseSections($dom) as $item) {
            if (in_array($item['type'], $types, true) === true) {
                $allItems[] = $this->buildFeedItem($item, $info, $hideAttachments);
            }
        }

        if ($allItems === []) {
            throwServerException('No items found. The site layout may have changed.');
        }

        usort($allItems, fn(array $a, array $b): int => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        $this->items = $allItems;
    }
}
