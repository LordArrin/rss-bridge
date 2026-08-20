<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class GigabyteSupportBridge extends BridgeAbstract
{
    const NAME = 'Gigabyte Support';
    const URI = 'https://www.gigabyte.com/';
    const DESCRIPTION = 'Returns BIOS and drivers updates for Gigabyte products';
    const MAINTAINER = 'LordArrin';
    const CACHE_TIMEOUT = 14400;
    const VALID_TYPES = ['driver', 'bios'];
    const PARAMETERS = [[
        'url' => [
            'name' => 'Support page URL',
            'type' => 'text',
            'required' => true,
            'title' => 'Full URL of the product support page on gigabyte.com (hash fragments like #Support-Bios or #Support-Driver are supported)'
        ],
        'hide_download_button' => [
            'name' => 'Hide download button',
            'type' => 'checkbox',
            'title' => 'Check this box to hide the download button from feed items'
        ]
    ]];

    private const CSS = [
        'item' => 'font-family:sans-serif;line-height:1.6;color:inherit',
        'p' => 'margin:8px 0',
        'link' => 'color:#ff6600;text-decoration:none;font-weight:500',
        'label' => 'font-weight:bold;color:inherit',
        'download' => 'display:inline-block;margin-top:10px;padding:8px 16px;background:#ff6600;color:#fff;text-decoration:none;border-radius:4px;font-weight:500',
    ];

    private ?array $productInfo = null;
    private ?string $pageContent = null;

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

        $html = $this->fetchPageContent();
        $productName = $html === null ? null : $this->extractProductName($html);
        $name = $productName === null ? str_replace('-', ' ', $info['product']) : $productName;

        $fragment = strtolower(preg_replace('/^support-/i', '', $info['fragment']));
        if (in_array($fragment, self::VALID_TYPES, true) === true) {
            $name .= ' (' . ($fragment === 'bios' ? 'BIOS' : ucfirst($fragment)) . ')';
        }

        return $name;
    }

    public function getURI(): string
    {
        $url = $this->getInput('url');
        return $url === null ? parent::getURI() : $url;
    }

    private function getProductInfo(): ?array
    {
        if ($this->productInfo !== null) {
            return $this->productInfo;
        }

        $url = $this->getInput('url');
        if ($url === null) {
            return null;
        }

        $parsedUrl = parse_url($url);
        $segments = array_values(array_filter(
            explode('/', trim($parsedUrl['path'] ?? '', '/')),
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

        $fragment = strtolower(preg_replace('/^support-/i', '', $info['fragment']));
        return in_array($fragment, self::VALID_TYPES, true) === true ? [$fragment] : self::VALID_TYPES;
    }

    private function fetchPageContent(): ?string
    {
        if ($this->pageContent !== null) {
            return $this->pageContent;
        }

        $info = $this->getProductInfo();
        if ($info === null) {
            return null;
        }

        try {
            $this->pageContent = getContents($this->supportUrl($info));
            return $this->pageContent;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractProductName(string $html): ?string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//*[contains(@class, 'model-base-info-title')]");
        
        foreach ($nodes as $node) {
            $name = trim($node->textContent);
            if ($name !== '') {
                return $name;
            }
        }
        
        return null;
    }

    private function getInnerHTML(\DOMNode $node): string
    {
        $innerHTML = '';
        foreach ($node->childNodes as $child) {
            $innerHTML .= $node->ownerDocument->saveHTML($child);
        }
        return $innerHTML;
    }

    private function normalize(string $text, bool $keepLinks = false): string
    {
        if ($text === '') {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/Checksum\s*:\s*\S+/i', '', $text);

        if ($keepLinks === true) {
            $text = preg_replace('/<li[^>]*>/i', '[[SEP]]', $text);
            $text = preg_replace('/<\/li>/i', '', $text);
            $text = preg_replace('/<br\s*\/?>/i', '[[SEP]]', $text);
            $text = preg_replace('/<\/?p[^>]*>/i', '[[SEP]]', $text);
            $text = preg_replace('/<\/?div[^>]*>/i', '[[SEP]]', $text);
            $text = preg_replace('/<ol[^>]*>/i', '[[SEP]]', $text);
            $text = preg_replace('/<\/ol>/i', '', $text);
            $text = preg_replace('/<ul[^>]*>/i', '[[SEP]]', $text);
            $text = preg_replace('/<\/ul>/i', '', $text);

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
            }, $text);

            $text = strip_tags($text, '<a>');
            $parts = preg_split('/\[\[SEP\]\]|\n|\r\n?/', $text);

            $cleanParts = array_filter(
                array_map(fn(string $part): string => preg_replace('/\s+/', ' ', trim($part)), $parts),
                fn(string $part): bool => $part !== ''
            );

            return implode('<br>', $cleanParts);
        }

        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/<br\s*\/?>/i', ', ', $text);
        $text = preg_replace('/(64bit|32bit)\s+(Windows|Linux|macOS)/i', '$1, $2', $text);
        $text = strip_tags($text);
        $text = preg_replace('/,\s*,/', ',', $text);

        return trim($text, ', ');
    }

    private function parseSections(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $items = [];

        $h2Nodes = $xpath->query('//h2');
        foreach ($h2Nodes as $h2Node) {
            $category = trim($h2Node->textContent);
            if ($category === '') {
                continue;
            }

            $type = strtolower($category) === 'bios' ? 'bios' : 'driver';

            $tableNode = $h2Node->nextSibling;
            while ($tableNode !== null && $tableNode->nodeName !== 'table') {
                $tableNode = $tableNode->nextSibling;
            }

            if ($tableNode === null) {
                continue;
            }

            $rows = $xpath->query('.//tr', $tableNode);
            $hasOs = null;

            foreach ($rows as $row) {
                if ($row->getElementsByTagName('th')->length > 0) {
                    continue;
                }

                $cells = $row->getElementsByTagName('td');
                if ($cells->length < 4) {
                    continue;
                }

                if ($hasOs === null) {
                    $hasOs = $cells->length >= 6;
                }

                $descriptionNode = $cells->item(0);
                $versionNode = $cells->item(1);
                $osNode = $hasOs ? $cells->item(2) : null;
                $sizeNode = $cells->item($hasOs ? 3 : 2);
                $dateNode = $cells->item($hasOs ? 4 : 3);

                $downloadUrl = '';
                $links = $row->getElementsByTagName('a');
                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    if (str_contains($href, 'download.gigabyte.com') || str_contains($href, '.zip')) {
                        $downloadUrl = $href;
                        break;
                    }
                }
                
                if ($downloadUrl !== '' && str_starts_with($downloadUrl, '/')) {
                    $downloadUrl = 'https://www.gigabyte.com' . $downloadUrl;
                }

                $items[] = [
                    'type' => $type,
                    'category' => $category,
                    'description' => $this->normalize($this->getInnerHTML($descriptionNode), true),
                    'version' => $this->normalize($versionNode->textContent),
                    'os' => $osNode ? $this->normalize($osNode->textContent) : '',
                    'size' => $this->normalize($sizeNode->textContent),
                    'date' => $this->normalize($dateNode->textContent),
                    'download' => $downloadUrl
                ];
            }
        }

        return $items;
    }

    private function render(string $label, string $value, bool $lineBreak = false, bool $allowHtml = false): string
    {
        if ($value === '') {
            return '';
        }

        $displayValue = $allowHtml === true ? $value : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $displayValue = preg_replace('/^(?:\s|<br\s*\/?>)+/i', '', $displayValue);
        $displayValue = ltrim($displayValue);

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
            $rawTitle = preg_replace('/Checksum\s*:\s*\S+/i', '', $rawTitle);
            $itemTitle = preg_replace('/\s+/', ' ', trim(trim($rawTitle)));

            if (empty($data['version']) === false) {
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

        if ($hideAttachments === false && empty($data['download']) === false) {
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

        if (empty($data['date']) === false) {
            $timestamp = strtotime($data['date']);
            if ($timestamp !== false) {
                $item['timestamp'] = $timestamp;
            }
        }

        return $item;
    }

    public function collectData(): void
    {
        $info = $this->getProductInfo();
        if ($info === null || $info['product'] === '' || $info['category'] === '') {
            throwClientException('Invalid URL format or could not extract product info. Expected format: https://www.gigabyte.com/Category/Product-ID/support');
        }

        $html = $this->fetchPageContent();
        if ($html === null) {
            throwClientException('Failed to fetch support page content');
        }

        $hideAttachments = (bool) $this->getInput('hide_download_button');
        $types = $this->getDownloadTypes();
        $allItems = [];

        foreach ($this->parseSections($html) as $item) {
            if (in_array($item['type'], $types, true) === true) {
                $allItems[] = $this->buildFeedItem($item, $info, $hideAttachments);
            }
        }

        if (empty($allItems) === true) {
            return;
        }

        usort($allItems, fn(array $a, array $b): int => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        foreach ($allItems as $item) {
            $this->items[] = $item;
        }
    }
}
