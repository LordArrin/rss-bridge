<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\FeedItem;

use function RSSBridge\Exceptions\throwClientException;

final class MSISupportBridge extends BridgeAbstract
{
    public const NAME = 'MSI Support';
    public const URI = 'https://www.msi.com/';
    public const DESCRIPTION = 'Returns BIOS, drivers, manuals, and utilities updates for MSI products via internal API';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 14400;
    public const VALID_TYPES = ['bios', 'driver', 'manual', 'utility'];
    public const PARAMETERS = [
        [
            'url' => [
                'name' => 'Support page URL',
                'type' => 'text',
                'required' => true,
                'title' => 'Full URL of the product support page on msi.com (hash fragments like #bios or #utility are supported)'
            ],
            'hide_download_button' => [
                'name' => 'Hide download button',
                'type' => 'checkbox',
                'title' => 'Check this box to hide the download button from feed items'
            ]
        ]
    ];

    private const CSS = [
        'item'     => 'font-family:sans-serif;line-height:1.6',
        'p'        => 'margin:8px 0',
        'link'     => 'color:#cc0000;text-decoration:none;font-weight:500',
        'label'    => 'font-weight:bold',
        'download' => 'display:inline-block;margin-top:10px;padding:8px 16px;background:#cc0000;color:#fff;text-decoration:none;border-radius:4px;font-weight:500',
    ];

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

        $displayName = $info['sub_product'] !== '' ? $info['sub_product'] : $info['product'];
        $name = str_replace('-', ' ', $displayName);

        if ($info['fragment'] !== '') {
            $fragmentName = strtolower($info['fragment']) === 'bios' ? 'BIOS' : ucfirst($info['fragment']);
            $name .= ' (' . $fragmentName . ')';
        }

        return $name;
    }

    public function getURI(): string
    {
        $url = $this->getInput('url');
        if ($url === null || $url === '') {
            return parent::getURI();
        }
        return $url;
    }

    private function getProductInfo(): ?array
    {
        if ($this->productInfo !== null) {
            return $this->productInfo;
        }

        $url = $this->getInput('url');
        if ($url === null || $url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false) {
            return null;
        }

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

        $product = array_pop($segments);
        $category = array_pop($segments);

        $subProduct = '';
        if (isset($parsedUrl['query']) === true && $parsedUrl['query'] !== '') {
            $queryParams = [];
            parse_str($parsedUrl['query'], $queryParams);
            if (isset($queryParams['sub_product']) === true && $queryParams['sub_product'] !== '') {
                $subProduct = $queryParams['sub_product'];
            }
        }

        $this->productInfo = [
            'product'     => $product,
            'category'    => $category,
            'fragment'    => $parsedUrl['fragment'] ?? '',
            'sub_product' => $subProduct
        ];

        return $this->productInfo;
    }

    private function getDownloadTypes(): array
    {
        $fragment = $this->getProductInfo()['fragment'] ?? '';
        $types = in_array($fragment, self::VALID_TYPES, true) === true ? [$fragment] : self::VALID_TYPES;

        return array_values(array_filter($types, fn(string $t): bool => $t !== 'manual'));
    }

    private function fetchApiData(string $type): ?array
    {
        $info = $this->getProductInfo();
        if ($info === null) {
            return null;
        }

        $apiProduct = $info['sub_product'] !== '' ? $info['sub_product'] : $info['product'];
        $url = self::URI . 'api/v1/product/support/panel?product=' . urlencode($apiProduct) . '&type=' . urlencode($type);

        try {
            $json = getContents($url, ['Accept: application/json']);
            if (json_validate($json) === false) {
                return null;
            }
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if ($data === null || isset($data['result']['downloads']) === false) {
                return null;
            }
            return $data['result']['downloads'];
        } catch (\Exception) {
            return null;
        }
    }

    private function decodeHtml(?string $str): string
    {
        if ($str === null || $str === '') {
            return '';
        }
        return html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function cleanDescription(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $linkStyle = self::CSS['link'];
        $callback = function (array $m) use ($linkStyle): string {
            $attrs = $m[1];
            if (stripos($attrs, 'target=') === false) {
                $attrs .= ' target="_blank"';
            }
            if (stripos($attrs, 'rel=') === false) {
                $attrs .= ' rel="noopener noreferrer"';
            }
            if (stripos($attrs, 'style=') === false) {
                $attrs .= ' style="' . $linkStyle . '"';
            }
            return '<a ' . trim($attrs) . '>';
        };

        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s*(?:style|on\w+)\s*=\s*(["\']).*?\1/i', '', $text);
        $text = strip_tags($text, '<a><br><p>');
        $text = preg_replace_callback('/<a\s+([^>]*?)>/is', $callback, $text);
        $text = nl2br($text);

        return $text;
    }

    private function buildFeedItem(array $file, string $subCategory, array $info, bool $hideAttachments): array
    {
        $title = $this->decodeHtml($file['download_title'] ?? $subCategory);
        $version = $this->decodeHtml($file['download_version'] ?? '');
        $itemTitle = "[{$subCategory}] {$title}";
        if ($version !== '') {
            $itemTitle .= " - {$version}";
        }

        $uri = "https://www.msi.com/{$info['category']}/{$info['product']}/support";
        if ($info['sub_product'] !== '') {
            $uri .= '?sub_product=' . urlencode($info['sub_product']);
        }
        if ($info['fragment'] !== '') {
            $uri .= '#' . $info['fragment'];
        }

        $content = '<div style="' . self::CSS['item'] . '">';

        if (isset($file['download_description']) === true && $file['download_description'] !== '') {
            $desc = $this->cleanDescription($file['download_description']);
            $content .= '<p style="' . self::CSS['p'] . '"><span style="' . self::CSS['label'] . '">Description:</span><br>' . $desc . '</p>';
        }
        if (isset($file['os']) === true && $file['os'] !== '') {
            $os = htmlspecialchars($this->decodeHtml($file['os']), ENT_QUOTES, 'UTF-8');
            $content .= '<p style="' . self::CSS['p'] . '"><span style="' . self::CSS['label'] . '">OS:</span> ' . $os . '</p>';
        }
        if (isset($file['download_size']) === true && $file['download_size'] !== '') {
            $sizeMb = round((float) $file['download_size'] / (1024 * 1024), 2);
            $content .= '<p style="' . self::CSS['p'] . '"><span style="' . self::CSS['label'] . '">Size:</span> ' . $sizeMb . ' MB</p>';
        }
        if ($hideAttachments === false && isset($file['download_url']) === true && $file['download_url'] !== '') {
            $downloadUrl = htmlspecialchars($file['download_url'], ENT_QUOTES, 'UTF-8');
            $downloadStyle = self::CSS['download'];
            $content .= sprintf(
                '<p style="%s"><a href="%s" style="%s" target="_blank" rel="noopener noreferrer">Download</a></p>',
                self::CSS['p'],
                $downloadUrl,
                $downloadStyle
            );
        }

        $content .= '</div>';

        $item = [
            'title'   => $itemTitle,
            'uri'     => $uri,
            'content' => $content,
            'uid'     => $file['download_id'] ?? md5($itemTitle)
        ];

        if (isset($file['download_release']) === true && $file['download_release'] !== '') {
            $timestamp = strtotime($file['download_release']);
            $item['timestamp'] = $timestamp === false ? null : $timestamp;
        }

        return $item;
    }

    public function collectData(): void
    {
        $info = $this->getProductInfo();
        if ($info === null || $info['product'] === '' || $info['category'] === '') {
            throwClientException('Invalid URL format or could not extract product info. Expected format: https://www.msi.com/Category/Product-ID/support');
        }

        $hideAttachments = (bool) $this->getInput('hide_download_button');
        $allItems = [];

        foreach ($this->getDownloadTypes() as $type) {
            $downloads = $this->fetchApiData($type);
            if ($downloads === null) {
                continue;
            }

            foreach ($downloads as $subCategory => $files) {
                if (strcasecmp((string) $subCategory, 'manual') === 0) {
                    continue;
                }

                if (is_array($files) === false || array_is_list($files) === false) {
                    continue;
                }

                foreach ($files as $file) {
                    if (is_array($file) === false) {
                        continue;
                    }
                    if (($file['download_url'] ?? '') === '' && ($file['download_title'] ?? '') === '') {
                        continue;
                    }
                    $allItems[] = $this->buildFeedItem($file, $subCategory, $info, $hideAttachments);
                }
            }
        }

        if ($allItems === []) {
            return;
        }

        usort($allItems, fn(array $a, array $b): int => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        foreach ($allItems as $item) {
            $this->items[] = $item;
        }
    }
}
