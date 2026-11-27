<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

final class MicrosoftUpdateCatalogBridge extends BridgeAbstract
{
    public const NAME = 'Microsoft Update Catalog';
    public const URI = 'https://www.catalog.update.microsoft.com/';
    public const DESCRIPTION = 'Returns Microsoft Update Catalog search results sorted by newest, with download links';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const MAX_LIMIT = 25;
    private const DOWNLOAD_CACHE_TTL = 604800;

    private const CSS_WRAPPER = 'font-size:14px; line-height:1.6; word-wrap:break-word;';
    private const CSS_META = 'margin:0 0 12px 0;';
    private const CSS_BUTTON_ROW = 'margin:8px 0;';
    private const CSS_BUTTON = 'display:inline-block; padding:8px 14px; background:#0067b8; color:#ffffff; text-decoration:none; border-radius:2px; font-weight:600;';

    public const PARAMETERS = [
        '' => [
            'url' => [
                'name' => 'Search query or URL',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'AMD RAID Controller',
                'title' => 'Paste a search term or a catalog search URL. It will be sorted by Last Updated (newest first)',
            ],
            'limit' => [
                'name' => 'Item limit (max 25)',
                'type' => 'number',
                'defaultValue' => 10,
            ],
        ],
    ];

    private string $searchUrl = '';
    private string $query = '';

    public function getName(): string
    {
        return $this->query !== '' ? 'Update Catalog: ' . $this->query : self::NAME;
    }

    public function getURI(): string
    {
        return $this->searchUrl !== '' ? $this->searchUrl : self::URI;
    }

    public function collectData(): void
    {
        $this->searchUrl = $this->buildSearchUrl(trim((string) $this->getInput('url')));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($this->getInput('limit') ?? self::MAX_LIMIT)));

        $html = (string) getContents($this->searchUrl, ['Referer: ' . self::URI]);
        $dom = parseHtmlDocument($html);

        $table = $dom->querySelector('#ctl00_catalogBody_updateMatches');
        if ($table === null) {
            if ($dom->querySelector('#ctl00_catalogBody_noResults') !== null) {
                throwClientException('No updates matched the search query.');
            }
            throwServerException('Results table not found. The catalog layout may have changed.');
        }

        $rowCount = 0;
        foreach ($table->querySelectorAll('tr[id]') as $row) {
            if ($rowCount >= $limit) {
                break;
            }

            $id = $row->getAttribute('id') ?? '';
            if (preg_match('/^([0-9a-fA-F-]{36})_R\d+$/', $id, $m) !== 1) {
                continue;
            }
            $rowCount++;
            $guid = strtolower($m[1]);

            $titleLink = $row->querySelector('a[id$="_link"]');
            $title = $titleLink !== null ? trim($titleLink->textContent ?? '') : '';
            if ($title === '') {
                continue;
            }

            $dateText = $this->cellText($row, 'C4');
            $timestamp = strtotime($dateText);
            if ($timestamp === false) {
                $timestamp = time();
            }

            $classification = $this->cellText($row, 'C3');
            $files = $this->fetchDownloads($guid);

            $this->items[] = [
                'title' => $title,
                'uri' => self::URI . 'ScopedView.aspx?updateid=' . $guid,
                'content' => $this->buildContent($row, $classification, $timestamp, $guid, $files),
                'timestamp' => $timestamp,
                'uid' => $guid,
                'categories' => $classification !== '' ? [$classification] : [],
            ];
        }

        if ($this->items === []) {
            throwServerException('No updates found in the search results.');
        }
    }

    private function buildSearchUrl(string $input): string
    {
        $input = trim($input);

        if (stripos($input, 'http://') !== 0 && stripos($input, 'https://') !== 0) {
            $this->query = $input;
            if ($this->query === '') {
                throwClientException('The search query cannot be empty.');
            }
            return self::URI . 'Search.aspx?q=' . rawurlencode($this->query) . '&scol=DateComputed&sdir=desc';
        }

        $parts = parse_url($input);
        if (is_array($parts) === false) {
            throwClientException('Invalid URL. Paste a link like https://www.catalog.update.microsoft.com/Search.aspx?q=aquantia or just a search term.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $path = strtolower($parts['path'] ?? '');

        if (
            in_array($scheme, ['http', 'https'], true) === false
            || in_array($host, ['www.catalog.update.microsoft.com', 'catalog.update.microsoft.com'], true) === false
            || $path !== '/search.aspx'
        ) {
            throwClientException('The URL must be a catalog search link: https://www.catalog.update.microsoft.com/Search.aspx?q=...');
        }

        $params = [];
        parse_str($parts['query'] ?? '', $params);

        $qValue = $params['q'] ?? '';
        $this->query = is_array($qValue) === true ? trim((string) ($qValue[0] ?? '')) : trim((string) $qValue);

        if ($this->query === '') {
            throwClientException('The search URL must contain a non-empty q parameter.');
        }

        return self::URI . 'Search.aspx?q=' . rawurlencode($this->query) . '&scol=DateComputed&sdir=desc';
    }

    private function cellText(\Dom\Element $row, string $cell): string
    {
        $td = $row->querySelector('td[id*="_' . $cell . '_"]');
        $text = trim($td !== null ? ($td->textContent ?? '') : '');
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @return list<array{url: string, name: string}>
     */
    private function fetchDownloads(string $guid): array
    {
        $cacheKey = 'downloads_' . $guid;
        $cached = $this->loadCacheValue($cacheKey);
        if (is_array($cached) === true) {
            $files = [];
            foreach ($cached as $entry) {
                if (
                    is_array($entry) === true
                    && isset($entry['url'], $entry['name']) === true
                    && is_string($entry['url']) === true
                    && is_string($entry['name']) === true
                ) {
                    $files[] = ['url' => $entry['url'], 'name' => $entry['name']];
                }
            }
            return $files;
        }

        $dialogUrl = $this->dialogUrl($guid);
        $html = (string) getContents($dialogUrl, ['Referer: ' . $this->searchUrl]);

        preg_match_all('/downloadInformation\[0\]\.files\[(\d+)\]\.url\s*=\s*\'([^\']+)\'/', $html, $urls, PREG_SET_ORDER);
        preg_match_all('/downloadInformation\[0\]\.files\[(\d+)\]\.fileName\s*=\s*\'([^\']+)\'/', $html, $names, PREG_SET_ORDER);

        $nameMap = [];
        foreach ($names as $n) {
            $nameMap[(int) $n[1]] = $n[2];
        }

        $files = [];
        foreach ($urls as $u) {
            $url = $u[2];
            $path = parse_url($url, PHP_URL_PATH);
            $fallback = basename(is_string($path) === true ? $path : '');
            $files[] = ['url' => $url, 'name' => $nameMap[(int) $u[1]] ?? $fallback];
        }

        if ($files !== []) {
            $this->saveCacheValue($cacheKey, $files, self::DOWNLOAD_CACHE_TTL);
        }

        return $files;
    }

    private function dialogUrl(string $guid): string
    {
        $payload = Json::encode([['size' => 0, 'languages' => '', 'uidInfo' => $guid, 'updateID' => $guid]], false);
        return self::URI . 'DownloadDialog.aspx?updateIDs=' . rawurlencode($payload);
    }

    /**
     * @param list<array{url: string, name: string}> $files
     */
    private function buildContent(\Dom\Element $row, string $classification, int $timestamp, string $guid, array $files): string
    {
        $meta = [];

        $products = $this->cellText($row, 'C2');
        if ($products !== '') {
            $meta[] = '<b>Products:</b> ' . e($products);
        }
        if ($classification !== '') {
            $meta[] = '<b>Classification:</b> ' . e($classification);
        }
        $version = $this->cellText($row, 'C5');
        if ($version !== '' && $version !== 'n/a') {
            $meta[] = '<b>Version:</b> ' . e($version);
        }
        $size = $row->querySelector('span[id$="_size"]');
        $sizeText = trim($size !== null ? ($size->textContent ?? '') : '');
        if ($sizeText !== '') {
            $meta[] = '<b>Size:</b> ' . e($sizeText);
        }

        $content = sprintf('<div style="%s"><p style="%s">%s</p>', self::CSS_WRAPPER, self::CSS_META, implode('<br />', $meta));

        if ($files !== []) {
            foreach ($files as $file) {
                $content .= sprintf(
                    '<p style="%s"><a href="%s" rel="noopener" style="%s">Download</a></p>',
                    self::CSS_BUTTON_ROW,
                    e($file['url']),
                    self::CSS_BUTTON
                );
            }
        } else {
            $content .= sprintf(
                '<p style="%s"><a href="%s" rel="noopener">Open download page</a></p>',
                self::CSS_BUTTON_ROW,
                e($this->dialogUrl($guid))
            );
        }

        return $content . '</div>';
    }
}
