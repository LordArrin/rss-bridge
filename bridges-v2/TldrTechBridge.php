<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class TldrTechBridge extends BridgeAbstract
{
    public const NAME = 'TLDR Tech Newsletter';
    public const URI = 'https://tldr.tech/';
    public const DESCRIPTION = 'Returns newsletter articles from TLDR Tech';
    public const MAINTAINER = 'No maintainer';

    public const CACHE_TIMEOUT = 10800;

    private const MAX_TITLE_LENGTH = 60;
    private const MIN_TITLE_SPACE_POS = 30;

    public const PARAMETERS = [
        '' => [
            'topic' => [
                'name' => 'Topic',
                'type' => 'list',
                'values' => [
                    'Tech' => 'tech',
                    'Dev' => 'dev',
                    'AI' => 'ai',
                    'Information Security' => 'infosec',
                    'Product Management' => 'product',
                    'DevOps' => 'devops',
                    'Crypto' => 'crypto',
                    'Design' => 'design',
                    'Marketing' => 'marketing',
                    'Founders' => 'founders',
                    'Fintech' => 'fintech',
                    'Data' => 'data',
                    'IT' => 'it',
                    'Hardware' => 'hardware',
                ],
                'defaultValue' => 'tech',
            ],
            'limit' => [
                'name' => 'Maximum number of articles to return',
                'type' => 'number',
                'required' => true,
                'defaultValue' => 10,
                'exampleValue' => 10,
            ],
            'include_sponsors' => [
                'name' => 'Include sponsored articles',
                'type' => 'checkbox',
                'defaultValue' => false,
            ],
            'hide_categories' => [
                'name' => 'Hide tags',
                'type' => 'checkbox',
                'defaultValue' => false,
            ],
        ],
    ];

    public function collectData(): void
    {
        $topic = (string) $this->getInput('topic');
        $limit = (int) $this->getInput('limit');
        $includeSponsors = (bool) $this->getInput('include_sponsors');
        $hideCategories = (bool) $this->getInput('hide_categories');

        $archivesUrl = self::URI . $topic . '/archives';
        $archivesHtml = (string) getContents($archivesUrl);

        if ($archivesHtml === '') {
            throwServerException('Failed to fetch archives');
        }

        $archivesDom = \Dom\HTMLDocument::createFromString($archivesHtml);
        $archivesContentDiv = $archivesDom->querySelector('div.content-center');

        if ($archivesContentDiv === null) {
            throwServerException('Could not find content div in archives');
        }

        $links = $archivesContentDiv->querySelectorAll('a');
        $editionUrls = [];

        foreach ($links as $link) {
            if ($link instanceof \Dom\Element === false) {
                continue;
            }

            $href = $link->getAttribute('href');
            if ($href === null || $href === '') {
                continue;
            }

            $absoluteUrl = urljoin(self::URI, $href);
            $editionDate = $this->extractDateFromUrl($absoluteUrl);

            if ($editionDate === false) {
                continue;
            }

            $editionUrls[] = [
                'url' => $absoluteUrl,
                'timestamp' => $editionDate,
            ];
        }

        foreach ($editionUrls as $edition) {
            if (count($this->items) >= $limit) {
                break;
            }

            $this->processEdition($edition['url'], $edition['timestamp'], $includeSponsors, $topic, $hideCategories);
        }
    }

    private function processEdition(string $url, int $timestamp, bool $includeSponsors, string $topic, bool $hideCategories): void
    {
        $cacheKey = 'tldr_edition_' . md5($url);
        $cachedHtml = $this->loadCacheValue($cacheKey);

        if (is_string($cachedHtml) === true && $cachedHtml !== '') {
            $html = $cachedHtml;
        } else {
            try {
                $html = (string) getContents($url);
                if ($html === '') {
                    return;
                }
                $this->saveCacheValue($cacheKey, $html, 86400);
            } catch (\Exception $e) {
                return;
            }
        }

        $dom = \Dom\HTMLDocument::createFromString($html);
        $editionContentDiv = $dom->querySelector('div.content-center');

        if ($editionContentDiv === null) {
            return;
        }

        $sections = $editionContentDiv->querySelectorAll('section');

        foreach ($sections as $section) {
            if ($section instanceof \Dom\Element === false) {
                continue;
            }

            $sectionName = '';
            $header = $section->querySelector('header');
            if ($header !== null) {
                $sectionH3 = $header->querySelector('h3');
                if ($sectionH3 !== null) {
                    $sectionName = trim($sectionH3->textContent);
                }
            }

            $articles = $section->querySelectorAll('article');

            foreach ($articles as $article) {
                if ($article instanceof \Dom\Element === false) {
                    continue;
                }

                $linkElement = $article->querySelector('a.font-bold');
                if ($linkElement === null) {
                    continue;
                }

                $articleH3 = $linkElement->querySelector('h3');
                if ($articleH3 === null) {
                    continue;
                }

                $title = trim($articleH3->textContent);
                if ($title === '') {
                    continue;
                }

                $isSponsor = stripos($title, '(sponsor)') !== false ||
                             stripos($title, '(sponsored)') !== false;

                if ($isSponsor === true && $includeSponsors === false) {
                    continue;
                }

                $href = $linkElement->getAttribute('href');
                if ($href === null || $href === '') {
                    continue;
                }

                $newsletterHtmlDiv = $article->querySelector('div.newsletter-html');
                $content = '';

                if ($newsletterHtmlDiv !== null) {
                    $content = $newsletterHtmlDiv->innerHTML ?? '';
                    if ($content !== '') {
                        $content = break_annoying_html_tags($content);
                    }
                }

                if ($content === '') {
                    $content = '<p>' . e($title) . '</p>';
                }

                $cleanTitle = $this->truncateTitle($title);

                $item = [
                    'title' => $cleanTitle,
                    'uri' => $href,
                    'content' => $content,
                    'timestamp' => $timestamp,
                    'uid' => md5($href),
                    'author' => 'TLDR',
                ];

                if ($hideCategories === false) {
                    $categories = [$topic];
                    if ($sectionName !== '') {
                        $categories[] = $sectionName;
                    }
                    $item['categories'] = $categories;
                }

                $this->items[] = $item;
            }
        }
    }

    private function truncateTitle(string $title): string
    {
        $title = preg_replace('/\s*\(\d+\s+minute\s+read\)\s*$/i', '', $title);

        if (mb_strlen($title) <= self::MAX_TITLE_LENGTH) {
            return $title;
        }

        $cut = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
        $sp = mb_strrpos($cut, ' ');

        if ($sp !== false && $sp > self::MIN_TITLE_SPACE_POS) {
            return rtrim(mb_substr($cut, 0, $sp)) . '...';
        }

        return rtrim($cut) . '...';
    }

    private function extractDateFromUrl(string $url): int|false
    {
        $pattern = '/(\d{4})-(\d{2})-(\d{2})$/';
        if (preg_match($pattern, $url, $matches) === 1) {
            $dateString = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            $timestamp = strtotime($dateString);
            return $timestamp !== false ? $timestamp : false;
        }
        return false;
    }
}
