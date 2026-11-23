<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class ASRockSupportBridge extends BridgeAbstract
{
    public const NAME = 'ASRock Support';
    public const URI = 'https://www.asrock.com/';
    public const DESCRIPTION = 'Returns BIOS or driver updates for ASRock products';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 14400;

    public const VALID_TYPES = ['bios', 'driver'];

    public const PARAMETERS = [
        '' => [
            'url' => [
                'name' => 'Product page URL',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'https://www.asrock.com/MB/Intel/B760M%20Pro%20RS/index.asp',
                'title' => 'Product page on any asrock.com host (www, pg, ...); index.asp, a trailing slash and hash fragments are all optional',
            ],
            'source' => [
                'name' => 'Section',
                'type' => 'list',
                'values' => [
                    'BIOS' => 'bios',
                    'Drivers' => 'driver',
                ],
                'required' => true,
                'defaultValue' => 'bios',
                'title' => 'BIOS updates or driver downloads',
            ],
            'hide_download_button' => [
                'name' => 'Hide download button',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Hide the download button from feed items',
            ],
        ],
        'global' => [
            'proxy_profile' => [
                'name' => 'Proxy profile',
                'type' => 'list',
                'values' => [
                    'Trawl' => 'trawl',
                ],
                'required' => true,
                'defaultValue' => 'trawl',
            ],
        ],
    ];

    private const CSS = [
        'item' => 'font-family:sans-serif;line-height:1.6;color:inherit',
        'p' => 'margin:8px 0',
        'text' => 'margin:8px 0',
        'warning' => 'margin:8px 0;padding:4px 0 4px 12px;border-left:4px solid #cf222e',
        'label' => 'font-weight:bold;color:inherit',
        'link' => 'color:#0071bc;text-decoration:none;font-weight:500',
        'download' => 'display:inline-block;margin-top:10px;padding:8px 16px;background:#0071bc;color:#fff;text-decoration:none;border-radius:4px;font-weight:500',
    ];

    private const HEADER_ALIASES = [
        'version' => 'version',
        'date' => 'date',
        'size' => 'size',
        'update method' => 'method',
        'description' => 'description',
        'os' => 'os',
        'download' => 'download',
    ];

    private const BLOCK_TAGS = ['p', 'div', 'li', 'tr', 'ul', 'ol', 'table'];

    /** @var array{model: string, base: string}|null */
    private ?array $productInfo = null;

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

        return $info['model'] . ($this->getSource() === 'bios' ? ' (BIOS)' : ' (Drivers)');
    }

    public function getURI(): string
    {
        $info = $this->getProductInfo();
        if ($info === null) {
            return parent::getURI();
        }

        return $info['base'] . 'index.asp#' . ($this->getSource() === 'bios' ? 'BIOS' : 'Download');
    }

    public function collectData(): void
    {
        $info = $this->getProductInfo();
        if ($info === null) {
            throwClientException('Invalid URL. Expected a product page on asrock.com, e.g. https://www.asrock.com/MB/Intel/B760M%20Pro%20RS/index.asp');
        }

        $profile = (string) $this->getInput('proxy_profile');
        if ($profile === '') {
            $profile = 'trawl';
        }

        $hideDownload = (bool) $this->getInput('hide_download_button');
        $source = $this->getSource();
        $pageUrl = $info['base'] . ($source === 'bios' ? 'BIOS.html' : 'Download.html');

        $html = getProtectedContents($pageUrl, $profile, ['cache_ttl' => self::CACHE_TIMEOUT]);
        if ($html === '') {
            throwServerException('Empty response from ' . $pageUrl);
        }

        $dom = parseHtmlDocument($html);
        $items = [];

        foreach ($this->parseTables($dom, $pageUrl, $source) as $row) {
            $items[] = $this->buildFeedItem($row, $info, $hideDownload);
        }

        if ($items === []) {
            throwServerException('No items found. The site layout may have changed.');
        }

        usort($items, fn(array $a, array $b): int => (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0));

        $this->items = $items;
    }

    private function getSource(): string
    {
        $source = (string) $this->getInput('source');

        return in_array($source, self::VALID_TYPES, true) === true ? $source : 'bios';
    }

    /**
     * @return array{model: string, base: string}|null
     */
    private function getProductInfo(): ?array
    {
        if ($this->productInfo !== null) {
            return $this->productInfo;
        }

        $url = $this->getInput('url');
        if (is_string($url) === false) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_contains($url, '://') === false) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host !== 'asrock.com' && str_ends_with($host, '.asrock.com') === false) {
            return null;
        }

        $path = $this->encodeSpaces($parts['path'] ?? '');
        $segments = array_values(array_filter(explode('/', $path), fn(string $s): bool => $s !== ''));
        if ($segments === []) {
            return null;
        }

        $last = $segments[count($segments) - 1] ?? '';
        if (preg_match('/\.(asp|aspx|html?)$/i', $last) === 1) {
            array_pop($segments);
        }

        if (count($segments) < 2) {
            return null;
        }

        $model = rawurldecode($segments[count($segments) - 1] ?? '');
        if ($model === '') {
            return null;
        }

        $this->productInfo = [
            'model' => $model,
            'base' => 'https://' . $host . '/' . implode('/', $segments) . '/',
        ];

        return $this->productInfo;
    }

    private function encodeSpaces(string $url): string
    {
        return str_replace(' ', '%20', $url);
    }

    /**
     * @return list<array{type: string, title: string, description: string, os: string, size: string, method: string, date: string, sha256: string, download: string}>
     */
    private function parseTables(\Dom\HTMLDocument $dom, string $pageUrl, string $type): array
    {
        $rows = [];

        foreach ($dom->querySelectorAll('table') as $table) {
            $map = $this->mapHeaders($table);
            if ($map === []) {
                continue;
            }

            $tableType = isset($map['version']) === true ? 'bios' : 'driver';
            if ($tableType !== $type) {
                continue;
            }

            foreach ($table->querySelectorAll('tr') as $tr) {
                if ($tr->querySelector('th') !== null) {
                    continue;
                }

                $cells = $this->cells($tr);
                if ($cells === []) {
                    continue;
                }

                $descriptionCell = $this->cell($cells, $map, 'description');
                if ($descriptionCell === null) {
                    continue;
                }

                if ($this->headerLabel($descriptionCell->textContent ?? '') === 'description') {
                    continue;
                }

                $lines = $this->descriptionLines($descriptionCell, $pageUrl);
                if ($lines === []) {
                    continue;
                }

                $title = $tableType === 'bios' ? $this->cellText($cells, $map, 'version') : $this->plainText(strip_tags($lines[0]['text']));
                if ($title === '') {
                    continue;
                }

                $rows[] = [
                    'type' => $tableType,
                    'title' => $title,
                    'description' => $this->descriptionHtml($lines),
                    'os' => $this->cellText($cells, $map, 'os', ', '),
                    'size' => $this->cellText($cells, $map, 'size'),
                    'method' => $this->cellText($cells, $map, 'method'),
                    'date' => $this->cellText($cells, $map, 'date'),
                    'sha256' => $this->extractChecksum($this->plainText(strip_tags($descriptionCell->innerHTML))),
                    'download' => $this->downloadUrl($tr, $pageUrl),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function mapHeaders(\Dom\Element $table): array
    {
        foreach ($table->querySelectorAll('tr') as $tr) {
            $cells = [];
            foreach ($tr->querySelectorAll('th') as $cell) {
                $cells[] = $cell;
            }

            if ($cells === []) {
                $cells = $this->cells($tr);
            }

            if ($cells === []) {
                continue;
            }

            $map = [];
            $index = 0;
            foreach ($cells as $cell) {
                $label = $this->headerLabel($cell->textContent ?? '');
                if (isset(self::HEADER_ALIASES[$label]) === true) {
                    $map[self::HEADER_ALIASES[$label]] = $index;
                }

                $span = (int) ($cell->getAttribute('colspan') ?? '1');
                $index += $span > 0 ? $span : 1;
            }

            if (isset($map['description']) === true && (isset($map['date']) === true || isset($map['version']) === true)) {
                return $map;
            }
        }

        return [];
    }

    /**
     * @return list<\Dom\Element>
     */
    private function cells(\Dom\Element $row): array
    {
        $cells = [];
        foreach ($row->querySelectorAll('td') as $cell) {
            $cells[] = $cell;
        }

        return $cells;
    }

    /**
     * @param list<\Dom\Element> $cells
     * @param array<string, int> $map
     */
    private function cell(array $cells, array $map, string $key): ?\Dom\Element
    {
        $index = $map[$key] ?? null;
        if ($index === null) {
            return null;
        }

        return $cells[$index] ?? null;
    }

    /**
     * @param list<\Dom\Element> $cells
     * @param array<string, int> $map
     */
    private function cellText(array $cells, array $map, string $key, string $separator = ' '): string
    {
        $cell = $this->cell($cells, $map, $key);
        if ($cell === null) {
            return '';
        }

        $html = preg_replace('/<br\s*\/?>/i', $separator, $cell->innerHTML) ?? $cell->innerHTML;

        return $this->plainText(strip_tags($html));
    }

    private function headerLabel(string $text): string
    {
        $text = strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text);
    }

    private function plainText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function extractChecksum(string $html): string
    {
        if (preg_match('/SHA256:\s*([0-9a-fA-F]{64})/', $html, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return '';
    }

    /**
     * @return list<array{text: string, warning: bool}>
     */
    private function descriptionLines(\Dom\Element $cell, string $pageUrl): array
    {
        $rendered = $this->renderNode($cell, $pageUrl);
        $rendered = preg_replace('/SHA256:(?:\s|\[\[BR\]\])*[0-9a-fA-F]{64}/', '', $rendered) ?? $rendered;

        $parts = preg_split('/\[\[BR\]\]/', $rendered);
        if ($parts === false) {
            $parts = [$rendered];
        }

        $lines = [];
        foreach ($parts as $part) {
            $line = trim(preg_replace('/\s+/u', ' ', $part) ?? $part);
            if ($line === '') {
                continue;
            }

            $warning = preg_match('/^\*+/', trim(strip_tags($line))) === 1;
            if ($warning === true) {
                $line = trim(preg_replace('/^((?:\s|<[^>]+>)*)\*+/', '$1', $line) ?? $line);
            }

            if ($line === '') {
                continue;
            }

            $lines[] = ['text' => $line, 'warning' => $warning];
        }

        return $lines;
    }

    /**
     * @param list<array{text: string, warning: bool}> $lines
     */
    private function descriptionHtml(array $lines): string
    {
        $html = '';
        $buffer = [];
        $bufferWarning = false;

        foreach ($lines as $line) {
            if ($buffer !== [] && $line['warning'] !== $bufferWarning) {
                $html .= $this->descriptionBlock($buffer, $bufferWarning);
                $buffer = [];
            }

            $buffer[] = $line['text'];
            $bufferWarning = $line['warning'];
        }

        if ($buffer !== []) {
            $html .= $this->descriptionBlock($buffer, $bufferWarning);
        }

        return $html;
    }

    /**
     * @param list<string> $lines
     */
    private function descriptionBlock(array $lines, bool $warning): string
    {
        $style = $warning === true ? self::CSS['warning'] : self::CSS['text'];

        return sprintf('<div style="%s">%s</div>', $style, implode('<br>', $lines));
    }

    private function renderNode(\Dom\Node $node, string $pageUrl): string
    {
        if ($node instanceof \Dom\Text) {
            return htmlspecialchars($node->textContent ?? '', ENT_QUOTES, 'UTF-8');
        }

        if ($node instanceof \Dom\Element === false) {
            return '';
        }

        if ($node->localName === 'br') {
            return '[[BR]]';
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->renderNode($child, $pageUrl);
        }

        if ($node->localName === 'a') {
            $href = $this->encodeSpaces(trim($node->getAttribute('href') ?? ''));
            if ($href === '' || str_starts_with($href, '#') === true || str_starts_with(strtolower($href), 'javascript:') === true) {
                return $inner;
            }

            $absolute = urljoin($pageUrl, $href);

            return '<a href="' . htmlspecialchars($absolute, ENT_QUOTES, 'UTF-8') . '" style="' . self::CSS['link'] . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>';
        }

        if (in_array($node->localName, self::BLOCK_TAGS, true) === true) {
            return $inner . '[[BR]]';
        }

        return $inner;
    }

    private function downloadUrl(\Dom\Element $row, string $pageUrl): string
    {
        $fallback = '';

        foreach ($row->querySelectorAll('a[href]') as $link) {
            $href = $this->encodeSpaces(trim($link->getAttribute('href') ?? ''));
            if ($href === '') {
                continue;
            }

            $lower = strtolower($href);
            if (str_contains($lower, 'asrockchina') === true || str_starts_with($lower, 'ftp:') === true) {
                continue;
            }

            if (str_starts_with($lower, 'https://download.asrock.com/') === true || str_starts_with($lower, 'http://download.asrock.com/') === true) {
                return $href;
            }

            if ($fallback === '' && (str_starts_with($lower, 'http') === true || str_starts_with($href, '/') === true)) {
                if (preg_match('/\.(zip|rar|7z|exe|pdf)(\?|$)/i', $href) === 1) {
                    $fallback = urljoin($pageUrl, $href);
                }
            }
        }

        return $fallback;
    }

    private function parseDate(string $date): ?int
    {
        if ($date === '') {
            return null;
        }

        if (preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$#', $date, $matches) === 1) {
            $timestamp = strtotime(sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]));
        } else {
            $timestamp = strtotime($date);
        }

        if ($timestamp === false || $timestamp <= 0 || $timestamp > time() + 86400 * 365) {
            return null;
        }

        return $timestamp;
    }

    private function render(string $label, string $value): string
    {
        if ($value === '') {
            return '';
        }

        return sprintf(
            '<p style="%s"><span style="%s">%s:</span> %s</p>',
            self::CSS['p'],
            self::CSS['label'],
            $label,
            htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
        );
    }

    private function renderDescription(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return sprintf('<p style="%s"><span style="%s">Description:</span></p>%s', self::CSS['p'], self::CSS['label'], $html);
    }

    /**
     * @param array{type: string, title: string, description: string, os: string, size: string, method: string, date: string, sha256: string, download: string} $data
     * @param array{model: string, base: string} $info
     * @return array{title: string, uri: string, content: string, uid: string, timestamp?: int}
     */
    private function buildFeedItem(array $data, array $info, bool $hideDownload): array
    {
        $title = $data['type'] === 'bios' ? '[BIOS] ' . $data['title'] : '[Drivers] ' . $data['title'];
        $uri = $info['base'] . 'index.asp#' . ($data['type'] === 'bios' ? 'BIOS' : 'Download');

        $content = '<div style="' . self::CSS['item'] . '">';
        $content .= $this->renderDescription($data['description']);
        $content .= $this->render('Update method', $data['method']);
        $content .= $this->render('OS', $data['os']);
        $content .= $this->render('Size', $data['size']);
        $content .= $this->render('SHA256', $data['sha256']);

        if ($hideDownload === false && $data['download'] !== '') {
            $content .= sprintf(
                '<p style="%s"><a href="%s" style="%s" target="_blank" rel="noopener noreferrer">Download</a></p>',
                self::CSS['p'],
                htmlspecialchars($data['download'], ENT_QUOTES, 'UTF-8'),
                self::CSS['download']
            );
        }

        $content .= '</div>';

        $item = [
            'title' => $title,
            'uri' => $uri,
            'content' => $content,
            'uid' => md5($info['model'] . '|' . $data['type'] . '|' . $data['title'] . '|' . $data['os']),
        ];

        $timestamp = $this->parseDate($data['date']);
        if ($timestamp !== null) {
            $item['timestamp'] = $timestamp;
        }

        return $item;
    }
}
