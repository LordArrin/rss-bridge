<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class DevToBridge extends BridgeAbstract
{
    public const NAME = 'DEV.to';
    public const URI = 'https://dev.to/';
    public const DESCRIPTION = 'Top posts and trending guides from DEV.to community';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'Top Posts' => [
            'period' => [
                'name' => 'Time Period',
                'type' => 'list',
                'values' => [
                    'Week' => 'week',
                    'Month' => 'month',
                    'Year' => 'year',
                    'All Time' => 'infinity',
                ],
                'defaultValue' => 'week',
            ],
            'limit' => [
                'name' => 'Number of Posts',
                'type' => 'number',
                'defaultValue' => 15,
            ],
        ],
        'Trending Guides' => [
            'limit' => [
                'name' => 'Number of Guides',
                'type' => 'number',
                'defaultValue' => 10,
            ],
        ],
    ];

    public function collectData(): void
    {
        if ($this->queriedContext === 'Top Posts') {
            $this->collectTopPosts();
        } else {
            $this->collectGuides();
        }

        if (empty($this->items) === true) {
            throwServerException('No items found. The site structure may have changed.');
        }
    }

    private function cleanArticleContent(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $dom = \Dom\HTMLDocument::createFromString($html);
        if ($dom === null) {
            return $html;
        }

        $selectorsToRemove = [
            '.highlight__panel',
            '.js-fullscreen-code-action',
            '.highlight__panel-action',
            '.crayons-icon',
            '.js-actions-panel',
            'button[class*="highlight"]',
            'div[class*="panel"][class*="action"]',
        ];

        foreach ($selectorsToRemove as $selector) {
            foreach ($dom->querySelectorAll($selector) as $element) {
                $element->remove();
            }
        }

        $savedHtml = $dom->saveHTML($dom->documentElement);
        $cleanedHtml = $savedHtml !== false ? $savedHtml : '';
        $cleaned = break_annoying_html_tags($cleanedHtml);

        return $cleaned;
    }

    private function collectTopPosts(): void
    {
        $period = (string)$this->getInput('period');

        $limitInput = $this->getInput('limit');
        $limit = $limitInput !== null && $limitInput !== '' ? (int)$limitInput : 15;

        // Calculate date based on period
        $date = new \DateTime();
        switch ($period) {
            case 'week':
                $date->modify('-7 days');
                break;
            case 'month':
                $date->modify('-1 month');
                break;
            case 'year':
                $date->modify('-1 year');
                break;
            case 'infinity':
            default:
                $date->modify('-5 years');
                break;
        }

        $apiUrl = sprintf(
            'https://dev.to/search/feed_content?per_page=%d&sort_by=public_reactions_count&sort_direction=desc&approved=&class_name=Article&published_at[gte]=%s',
            $limit,
            urlencode($date->format('Y-m-d\TH:i:s.v\Z'))
        );

        $cacheKey = 'devto_api_' . md5($apiUrl);
        $success = false;
        $response = apcu_fetch($cacheKey, $success);

        if ($success === false) {
            try {
                $response = getContents($apiUrl, ['Accept: application/json']);
                apcu_store($cacheKey, $response, 300);
            } catch (\Exception $e) {
                throwServerException('Failed to fetch data from DEV.to API: ' . $e->getMessage());
            }
        }

        $data = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);

        if (isset($data['result']) === false) {
            throwServerException('Invalid API response structure');
        }

        if (is_array($data['result']) === false) {
            throwServerException('Invalid API response structure');
        }

        foreach ($data['result'] as $item) {
            $articleUrl = 'https://dev.to' . ($item['path'] ?? '');

            if ($articleUrl === 'https://dev.to') {
                continue;
            }

            if ($articleUrl === '') {
                continue;
            }

            $articleDom = getSimpleHTMLDOMCached($articleUrl, 86400);
            if ($articleDom === null) {
                continue;
            }

            $content = $articleDom->querySelector('.crayons-article__body')?->innerHTML ?? '';
            $content = $this->cleanArticleContent($content);

            $metadata = html_find_seo_metadata($articleDom->saveHTML());

            $publishedAtInt = isset($item['published_at_int']) === true ? (int)$item['published_at_int'] : time();

            $this->items[] = [
                'title' => $item['title'] ?? 'Untitled',
                'uri' => $articleUrl,
                'content' => $content,
                'timestamp' => $metadata['timestamp'] ?? $publishedAtInt,
                'author' => $item['user']['name'] ?? null,
                'categories' => $item['tag_list'] ?? [],
                'uid' => $articleUrl,
            ];
        }
    }

    private function collectGuides(): void
    {
        $limitInput = $this->getInput('limit');
        $limit = $limitInput !== null && $limitInput !== '' ? (int)$limitInput : 10;

        $dom = getSimpleHTMLDOM(self::URI);
        if ($dom === null) {
            throwServerException('Failed to fetch DEV.to homepage');
        }

        $guideLinks = $dom->querySelectorAll('.widget-link-list .crayons-link--contentful');

        $count = 0;
        foreach ($guideLinks as $link) {
            if ($count >= $limit) {
                break;
            }

            $href = $link->getAttribute('href');
            if ($href === null) {
                continue;
            }

            if ($href === '/') {
                continue;
            }

            $guideUrl = urljoin(self::URI, $href);
            $title = trim($link->textContent);

            if ($title === '') {
                continue;
            }

            $guideDom = getSimpleHTMLDOMCached($guideUrl, 86400);
            if ($guideDom === null) {
                continue;
            }

            $content = $guideDom->querySelector('.crayons-article__body')?->innerHTML ?? '';
            $content = $this->cleanArticleContent($content);

            $authorName = $guideDom->querySelector('.crayons-article__header__meta .fw-bold')?->textContent ?? null;

            $tags = [];
            foreach ($guideDom->querySelectorAll('.spec__tags .crayons-tag') as $tag) {
                $tagText = trim($tag->textContent);
                if ($tagText !== '') {
                    $tags[] = ltrim($tagText, '#');
                }
            }

            $dateStr = $guideDom->querySelector('time[datetime]')?->getAttribute('datetime');
            $timestamp = null;
            if ($dateStr !== null) {
                $parsedTime = strtotime($dateStr);
                if ($parsedTime !== false) {
                    $timestamp = $parsedTime;
                }
            }

            $finalTimestamp = time();
            if ($timestamp !== null) {
                $finalTimestamp = $timestamp;
            }

            $this->items[] = [
                'title' => $title,
                'uri' => $guideUrl,
                'content' => $content,
                'timestamp' => $finalTimestamp,
                'author' => $authorName !== null ? trim($authorName) : null,
                'categories' => $tags,
                'uid' => $guideUrl,
            ];

            $count++;
        }
    }
}
