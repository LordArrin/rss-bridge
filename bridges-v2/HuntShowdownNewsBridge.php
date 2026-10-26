<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class HuntShowdownNewsBridge extends BridgeAbstract
{
    public const NAME = 'Hunt Showdown News';
    public const URI = 'https://www.huntshowdown.com';
    public const DESCRIPTION = 'Returns the latest news from HuntShowdown.com/news';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const NEWS_PATH = '/news/tagged/news';

    public function collectData(): void
    {
        $newsUrl = self::URI . self::NEWS_PATH;
        $html = $this->fetchHtml($newsUrl);

        $articles = iterator_to_array($html->querySelectorAll('.col'));

        if ($articles === []) {
            throwServerException('No articles found');
        }

        array_shift($articles);

        foreach ($articles as $article) {
            $titleNode = $article->querySelector('h3');
            $contentNode = $article->querySelector('p');
            $imageNode = $article->querySelector('img');
            $linkNode = $article->querySelector('a');
            $dateNode = $article->querySelector('span');

            if ($titleNode === null || $linkNode === null) {
                continue;
            }

            $articleTitle = trim($titleNode->textContent);

            $href = $linkNode->getAttribute('href');
            if ($href === null || $href === '') {
                continue;
            }

            $articleUri = urljoin(self::URI, $href);

            $articleCoverUrl = null;
            if ($imageNode !== null) {
                $src = $imageNode->getAttribute('src');
                if ($src !== null && $src !== '') {
                    $articleCoverUrl = urljoin(self::URI, $src);
                }
            }

            $articleContent = '';
            if ($articleCoverUrl !== null) {
                $imgTag = '<img src="' . htmlspecialchars($articleCoverUrl, ENT_QUOTES, 'UTF-8') . '"';
                $imgTag .= ' alt="' . htmlspecialchars($articleTitle, ENT_QUOTES, 'UTF-8') . '"> <br/> <br/>';
                $articleContent .= $imgTag;
            }
            if ($contentNode !== null) {
                $articleContent .= htmlspecialchars(trim($contentNode->textContent), ENT_QUOTES, 'UTF-8');
            }

            $timestamp = time();
            if ($dateNode !== null) {
                $dateText = trim($dateNode->textContent);
                $parsed = strtotime($dateText);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }

            $this->items[] = [
                'uri' => $articleUri,
                'title' => $articleTitle,
                'content' => $articleContent,
                'timestamp' => $timestamp,
                'uid' => md5($articleUri),
            ];
        }

        if ($this->items === []) {
            throwServerException('No news items found');
        }
    }

    private function fetchHtml(string $url): \Dom\HTMLDocument
    {
        $html = getContents($url);

        if ($html === '') {
            throwServerException('Failed to fetch ' . $url);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }
}
