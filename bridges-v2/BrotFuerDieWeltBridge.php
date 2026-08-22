<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class BrotFuerDieWeltBridge extends BridgeAbstract
{
    public const NAME = 'Brot fur die Welt';
    public const URI = 'https://www.brot-fuer-die-welt.de';
    public const DESCRIPTION = 'Listet die letzten Blogeintrage bzw. Pressemitteilungen von Brot fur die Welt';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'newsType' => [
            'name' => 'Neuigkeitentyp',
            'type' => 'list',
            'values' => [
                'Blog' => 'blog',
                'Pressemitteilungen' => 'press',
            ],
            'defaultValue' => 'blog',
        ],
    ]];

    private const MAX_ARTICLES = 100;
    private const PRESS_FULL_FETCH_LIMIT = 20;

    public function collectData(): void
    {
        $newsType = (string)($this->getInput('newsType') ?? 'blog');
        $pageURI = $newsType === 'press' ? self::URI . '/presse/alle-pressemitteilungen/' : self::URI . '/blog/alle-beitraege/';

        $html = getContents($pageURI);
        if ($html === '') {
            throwServerException('Could not fetch: ' . $pageURI);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $articles = $dom->querySelectorAll('body div.news div.news-list-view div.article');
        if ($articles->length === 0) {
            throwServerException('Could not find articles for: ' . $pageURI);
        }

        $articlesArray = array_slice(iterator_to_array($articles), 0, self::MAX_ARTICLES);

        if ($newsType === 'blog') {
            $this->collectBlogArticles($articlesArray);
        } else {
            $this->collectPressArticles($articlesArray);
        }
    }

    private function collectBlogArticles(array $articles): void
    {
        foreach ($articles as $article) {
            $item = [];

            $teaserBadge = $article->querySelector('div.news-img-wrap div.teaser-badge');
            $category = '';
            if ($teaserBadge !== null) {
                $badgeText = trim($teaserBadge->textContent ?? '');
                if ($badgeText !== '') {
                    $category = ' (' . $badgeText . ')';
                }
            }

            $headline = $article->querySelector('h3.headline');
            if ($headline === null) {
                continue;
            }
            $item['title'] = trim($headline->textContent ?? '') . $category;

            $newsDateAuthor = $article->querySelector('span.news-list-date');
            if ($newsDateAuthor !== null) {
                $dateAuthorText = trim($newsDateAuthor->textContent ?? '');
                if ($dateAuthorText !== '') {
                    $splitDateAuthor = explode(' | ', $dateAuthorText);

                    $parsedDate = strtotime($splitDateAuthor[0]);
                    if ($parsedDate !== false) {
                        $item['timestamp'] = $parsedDate;
                    }

                    if (count($splitDateAuthor) > 1) {
                        $item['author'] = trim($splitDateAuthor[1]);
                    }
                }
            }

            $moreLink = $article->querySelector('div.teaser-text a.more-link');
            if ($moreLink === null) {
                continue;
            }
            $moreHref = $moreLink->getAttribute('href') ?? '';
            if ($moreHref === '') {
                continue;
            }
            $item['uri'] = urljoin(self::URI, $moreHref);

            $articleHTML = $this->getCachedArticleHtml($item['uri']);
            $description = '';

            if ($articleHTML !== '') {
                libxml_use_internal_errors(true);
                $articleDom = \Dom\HTMLDocument::createFromString($articleHTML);
                libxml_use_internal_errors(false);

                $introBox = $articleDom->querySelector('body div.intro-box p');
                if ($introBox !== null) {
                    $description = $articleDom->saveHTML($introBox);
                }
            }

            if ($description === '') {
                $teaserParagraph = $article->querySelector('div.teaser-text div p');
                if ($teaserParagraph !== null) {
                    $description = trim($teaserParagraph->textContent ?? '');
                }
            }

            $item['content'] = $description;

            $enclosureImg = $article->querySelector('div.news-img-wrap picture img');
            if ($enclosureImg !== null) {
                $imgSrc = $enclosureImg->getAttribute('src') ?? '';
                if ($imgSrc !== '') {
                    $item['enclosures'] = [urljoin(self::URI, $imgSrc)];
                }
            }

            $this->items[] = $item;
        }
    }

    private function collectPressArticles(array $articles): void
    {
        foreach ($articles as $i => $article) {
            $item = [];

            $titleSpan = $article->querySelector('div.header h3 span');
            if ($titleSpan === null) {
                continue;
            }
            $item['title'] = trim($titleSpan->textContent ?? '');

            $timeElement = $article->querySelector('div.footer span.news-list-date time');
            if ($timeElement !== null) {
                $timeText = trim($timeElement->textContent ?? '');
                if ($timeText !== '') {
                    $parsedDate = strtotime($timeText);
                    if ($parsedDate !== false) {
                        $item['timestamp'] = $parsedDate;
                    }
                }
            }

            $item['author'] = 'Brot fur die Welt (Evangelisches Werk fur Diakonie und Entwicklung e.V.)';

            $moreLink = $article->querySelector('div.teaser-text a.more-link');
            if ($moreLink === null) {
                continue;
            }
            $moreHref = $moreLink->getAttribute('href') ?? '';
            if ($moreHref === '') {
                continue;
            }
            $item['uri'] = urljoin(self::URI, $moreHref);

            $miniDescParagraph = $article->querySelector('div.teaser-text div p');
            $miniDescription = $miniDescParagraph !== null ? trim($miniDescParagraph->textContent ?? '') : '';

            if ($i > self::PRESS_FULL_FETCH_LIMIT) {
                $escapedUri = htmlspecialchars($item['uri'], ENT_QUOTES, 'UTF-8');
                $item['content'] = $miniDescription . '<br><br>Weiterlesen auf <a href="' . $escapedUri . '">brot-fuer-die-welt.de</a>';
            } else {
                $articleHTML = $this->getCachedArticleHtml($item['uri']);
                $description = '';

                if ($articleHTML !== '') {
                    libxml_use_internal_errors(true);
                    $articleDom = \Dom\HTMLDocument::createFromString($articleHTML);
                    libxml_use_internal_errors(false);

                    $newsTextWrap = $articleDom->querySelector('body article.article-section div.news-text-wrap');
                    if ($newsTextWrap !== null) {
                        $description = $articleDom->saveHTML($newsTextWrap);
                    }
                }

                if ($description === '') {
                    $description = $miniDescription;
                }

                $item['content'] = $description;
            }

            $this->items[] = $item;
        }
    }

    private function getCachedArticleHtml(string $uri): string
    {
        $cacheKey = 'brot_' . md5($uri);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (string)$cached;
        }

        $html = getContents($uri);
        if ($html !== '') {
            $this->cache->set($cacheKey, $html, 86400);
        }

        return $html;
    }
}
