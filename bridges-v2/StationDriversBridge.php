<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

use function urljoin;

final class StationDriversBridge extends FeedExpander
{
    public const NAME = 'Station-Drivers';
    public const URI = 'https://www.station-drivers.com/';
    public const DESCRIPTION = 'Fetches full driver information from Station-Drivers. Supports both RSS feeds and catalog pages.';
    public const MAINTAINER = 'LordArrin';

    public const CACHE_TIMEOUT = 259200;

    public const PARAMETERS = [
        '' => [
            'feed_url' => [
                'name' => 'RSS Feed or Catalog URL',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'https://www.station-drivers.com/index.php/en/component/remository/func-rss/747/no_html,1/lang,en-gb/',
                'title' => 'URL of the Station-Drivers RSS feed OR catalog page',
            ],
        ],
    ];

    private const CSS = [
        'button' => 'background-color: #170b17; color: #906119; border: 3px solid #906119; padding: 10px 25px; text-decoration: none; display: inline-block; font-weight: bold; font-family: sans-serif;',
        'block' => 'margin-bottom: 15px;',
        'list' => 'margin-top: 5px; margin-bottom: 15px; padding-left: 20px; list-style-type: disc;',
        'download_wrap' => 'margin-top: 20px; text-align: left;',
    ];

    private const EXCLUDED_LABELS = [
        'Submitted On:',
        'Submitted By:',
        'File Version:',
        'File Author:',
    ];

    public function collectData(): void
    {
        $url = (string) $this->getInput('feed_url');
        if ($url === '') {
            throwClientException('URL is required');
        }

        $feedUrl = $url;

        // If this is a catalog page, not an RSS feed - find the RSS feed URL inside
        if (str_contains($url, 'func-rss') === false) {
            $feedUrl = $this->findRssFeedFromPage($url);
            if ($feedUrl === '') {
                throwServerException('Could not find RSS feed URL on the catalog page');
            }
        }

        // Limit to 3 most recent posts
        $this->collectExpandableDatas($feedUrl, 3);
    }

    private function findRssFeedFromPage(string $url): string
    {
        $dom = getSimpleHTMLDOM($url);

        $container = $dom->querySelector('#remositorycontainer');
        if ($container instanceof \Dom\Element === false) {
            return '';
        }

        $rssLink = $container->querySelector('h2 a');
        if ($rssLink instanceof \Dom\Element === false) {
            return '';
        }

        $href = $rssLink->getAttribute('href');
        if (is_string($href) === false || $href === '') {
            return '';
        }

        if (str_contains($href, 'func-rss') === false) {
            return '';
        }

        return urljoin($url, $href);
    }

    public function getName(): string
    {
        // Try to generate a clean feed name from the first post title
        if (isset($this->items[0]['title']) === true) {
            $title = (string) $this->items[0]['title'];
            $cleanTitle = $this->cleanFeedTitle($title);
            if ($cleanTitle !== '') {
                return $cleanTitle;
            }
        }

        return parent::getName();
    }

    private function cleanFeedTitle(string $title): string
    {
        // Remove "Version X.X..." and everything after it
        $title = (string) preg_replace('/\s+Version\s+[\d.]+.*$/i', '', $title);
        // Remove trailing "WHQL", "Beta", "Alpha", "(2)", "(3)" etc.
        $title = (string) preg_replace('/\s+(?:WHQL|Beta|Alpha|\(\d+\))\s*$/i', '', $title);
        return trim($title);
    }

    protected function parseItem(array $item): array|false
    {
        $uri = (string) ($item['uri'] ?? '');
        if ($uri === '') {
            return false;
        }

        // Random delay between 1-3 seconds to avoid being banned
        usleep(random_int(1000000, 3000000));

        try {
            $dom = getSimpleHTMLDOM($uri);
        } catch (\Throwable $e) {
            return $item;
        }

        $this->removeUnwantedElements($dom);
        $this->cleanStyles($dom);
        $this->extractAuthor($dom, $item);

        $downloadLinkHtml = $this->extractDownloadLink($dom, $uri);
        $content = $this->buildContent($dom, $uri);

        if ($downloadLinkHtml !== '') {
            $content .= '<div style="' . self::CSS['download_wrap'] . '">' . $downloadLinkHtml . '</div>';
        }

        $item['content'] = $content;

        return $item;
    }

    private function extractAuthor(\Dom\HTMLDocument $dom, array &$item): void
    {
        $dtElements = $dom->querySelectorAll('dt');
        foreach ($dtElements as $dt) {
            if ($dt instanceof \Dom\Element === false) {
                continue;
            }
            if (trim($dt->textContent ?? '') === 'Submitted By:') {
                $dd = $dt->nextElementSibling;
                if ($dd instanceof \Dom\Element === true && strtolower($dd->tagName) === 'dd') {
                    $item['author'] = trim($dd->textContent ?? '');
                }
                break;
            }
        }
    }

    private function removeUnwantedElements(\Dom\HTMLDocument $dom): void
    {
        $selectors = ['.float-none', '#remositorythumbbox', '.remositorythumbset'];
        foreach ($selectors as $selector) {
            foreach ($dom->querySelectorAll($selector) as $el) {
                if ($el instanceof \Dom\Element === true) {
                    $el->remove();
                }
            }
        }
    }

    private function cleanStyles(\Dom\HTMLDocument $dom): void
    {
        foreach ($dom->querySelectorAll('[style]') as $el) {
            if ($el instanceof \Dom\Element === true) {
                $el->removeAttribute('style');
            }
        }

        foreach ($dom->querySelectorAll('*') as $el) {
            if ($el instanceof \Dom\Element === false) {
                continue;
            }
            $attrsToRemove = [];
            foreach ($el->attributes as $attr) {
                if (str_starts_with($attr->name, 'data-darkreader') === true) {
                    $attrsToRemove[] = $attr->name;
                }
            }
            foreach ($attrsToRemove as $attrName) {
                $el->removeAttribute($attrName);
            }
        }
    }

    private function extractDownloadLink(\Dom\HTMLDocument $dom, string $baseUrl): string
    {
        $h2 = $dom->querySelector('h2');
        if ($h2 instanceof \Dom\Element === false) {
            return '';
        }

        $downloadLink = $h2->querySelector('a[rel="nofollow"]');
        if ($downloadLink instanceof \Dom\Element === false) {
            return '';
        }

        $href = $downloadLink->getAttribute('href');
        if (is_string($href) === false || $href === '') {
            return '';
        }

        $absoluteUrl = urljoin($baseUrl, $href);

        return '<a href="' . e($absoluteUrl) . '" rel="nofollow" target="_blank" style="' . self::CSS['button'] . '">Download</a>';
    }

    private function buildContent(\Dom\HTMLDocument $dom, string $baseUrl): string
    {
        $content = '';
        $dtElements = $dom->querySelectorAll('dt');

        foreach ($dtElements as $dt) {
            if ($dt instanceof \Dom\Element === false) {
                continue;
            }

            $label = trim($dt->textContent ?? '');

            if (in_array($label, self::EXCLUDED_LABELS, true) === true) {
                continue;
            }

            $dd = $dt->nextElementSibling;
            if ($dd instanceof \Dom\Element === false || strtolower($dd->tagName) !== 'dd') {
                continue;
            }

            $value = $dd->innerHTML ?? '';
            if (trim($value) === '') {
                continue;
            }

            $value = $this->fixRelativeUrls($value, $baseUrl);

            // Special formatting for Description block (lists)
            if ($label === 'Description:') {
                $value = $this->formatDescriptionLists($value);
            }

            $content .= '<div style="' . self::CSS['block'] . '"><strong>' . e($label) . '</strong><br>' . $value . '</div>';
        }

        return $content;
    }

    private function formatDescriptionLists(string $html): string
    {
        $result = preg_replace_callback(
            '/<p><strong>([^<]+):<\/strong><br>(.*?)<\/p>/si',
            function (array $matches): string {
                $title = $matches[1];
                $rawList = $matches[2];

                $items = preg_split('/<br\s*\/?>/i', $rawList);
                if (is_array($items) === false) {
                    return $matches[0];
                }

                $cleanItems = [];
                foreach ($items as $line) {
                    $line = html_entity_decode((string) $line, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    // Strip ALL leading/trailing whitespace including non-breaking spaces (\x{00A0})
                    $line = (string) preg_replace('/^[\s\x{00A0}]+/u', '', $line);
                    $line = (string) preg_replace('/[\s\x{00A0}]+$/u', '', $line);

                    if ($line !== '') {
                        $cleanItems[] = '<li>' . $line . '</li>';
                    }
                }

                if (count($cleanItems) > 1) {
                    return '<p><strong>' . $title . ':</strong></p><ul style="' . self::CSS['list'] . '">' . implode('', $cleanItems) . '</ul>';
                }
                return $matches[0];
            },
            $html
        );

        if (is_string($result) === true) {
            return $result;
        }
        return $html;
    }

    private function fixRelativeUrls(string $html, string $baseUrl): string
    {
        $result = preg_replace_callback(
            '/(src|href)="([^"]+)"/i',
            function (array $matches) use ($baseUrl): string {
                $attr = $matches[1];
                $url = $matches[2];
                if (preg_match('/^https?:\/\//i', $url) === 1) {
                    return $matches[0];
                }
                return $attr . '="' . e(urljoin($baseUrl, $url)) . '"';
            },
            $html
        );

        if (is_string($result) === true) {
            return $result;
        }
        return $html;
    }
}
