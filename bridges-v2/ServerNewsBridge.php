<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class ServerNewsBridge extends BridgeAbstract
{
    public const NAME = 'ServerNews';
    public const URI = 'https://servernews.ru';
    public const DESCRIPTION = 'News from servernews.ru with optional full article content';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    private const RSS_URL = 'https://servernews.ru/rss/';
    private const DEFAULT_LIMIT = 10;

    private const CSS_IMG = 'display: block; float: none; clear: both; max-width: 100%; width: auto; height: auto; margin: 16px 0; padding: 0;';

    public const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => self::DEFAULT_LIMIT,
                'title' => 'Maximum number of posts to return',
            ],
            'full_content' => [
                'name' => 'Full content',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Fetch full article content instead of RSS preview',
            ],
        ]
    ];

    private function fetchRss(): \SimpleXMLElement
    {
        $response = getContents(self::RSS_URL);

        if ($response === '') {
            throw new \Exception('Failed to fetch RSS feed from ServerNews');
        }

        libxml_use_internal_errors(true);
        $rss = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($rss === false) {
            throw new \Exception('Failed to parse RSS feed from ServerNews');
        }

        return $rss;
    }

    private function extractUrlFromGuid(string $guid): string
    {
        $url = trim($guid);

        if (preg_match('/^(https?:\/\/[^\s#]+)/i', $url, $matches) === 1) {
            return rtrim($matches[1], '/');
        }

        return '';
    }

    private function extractHashtags(string $text): array
    {
        $tags = [];
        if (preg_match_all('/#([^\s#<]+)/u', $text, $matches) > 0) {
            foreach ($matches[1] as $tag) {
                $cleanTag = trim($tag);
                if ($cleanTag !== '' && in_array($cleanTag, $tags, true) === false) {
                    $tags[] = $cleanTag;
                }
            }
        }
        return $tags;
    }

    private function applyStyles(string $html): string
    {
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
        libxml_clear_errors();

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return $html;
        }

        foreach ($wrapper->querySelectorAll('img') as $img) {
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->removeAttribute('align');
            $img->removeAttribute('style');
            $img->setAttribute('style', self::CSS_IMG);
        }

        foreach ($wrapper->querySelectorAll('p, div, h1, h2, h3, h4, h5, h6') as $element) {
            $style = (string)($element->getAttribute('style') ?? '');
            if ($style !== '') {
                $newStyle = preg_replace('/text-align\s*:\s*center\s*;?/i', '', $style);
                if (is_string($newStyle) === true && $newStyle !== $style) {
                    $newStyle = trim($newStyle);
                    if ($newStyle === '') {
                        $element->removeAttribute('style');
                    } else {
                        $element->setAttribute('style', $newStyle);
                    }
                }
            }
            $element->setAttribute('align', 'left');
        }

        return (string)$wrapper->innerHTML;
    }

    private function fetchFullContent(string $url): array
    {
        $result = [
            'content' => '',
            'tags' => [],
            'author' => '',
            'date' => '',
        ];

        $cleanUrl = $this->extractUrlFromGuid($url);

        if ($cleanUrl === '' || filter_var($cleanUrl, FILTER_VALIDATE_URL) === false) {
            return $result;
        }

        $html = getContents($cleanUrl);

        if ($html === '') {
            return $result;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_clear_errors();

        $article = $dom->querySelector('div.inside, .inside');
        if ($article === null) {
            $article = $dom->querySelector('div.pubtext, div[itemprop="articleBody"], .pubtext');
        }

        if ($article === null) {
            return $result;
        }

        $dateElement = $article->querySelector('span.date, .date, span[itemprop="datePublished"]');
        $author = '';
        $date = '';

        if ($dateElement !== null) {
            $dateContent = $dateElement->getAttribute('content');
            if ($dateContent !== null && $dateContent !== '') {
                $date = $dateContent;
            }

            $authorElement = $dateElement->querySelector('span[itemprop="name"]');
            if ($authorElement !== null) {
                $author = trim($authorElement->textContent);
            } else {
                $dateText = trim($dateElement->textContent);
                if (preg_match('/,\s*(.+)$/u', $dateText, $matches) === 1) {
                    $author = trim($matches[1]);
                }
            }

            $dateElement->remove();
        }

        $taglist = $article->querySelector('div.taglist.right, .taglist.right, div.taglist');
        $tags = [];

        if ($taglist !== null) {
            foreach ($taglist->querySelectorAll('a.tag') as $tagLink) {
                $tagText = trim($tagLink->textContent);
                if ($tagText !== '' && in_array($tagText, $tags, true) === false) {
                    $tags[] = $tagText;
                }
            }
            $taglist->remove();
        }

        foreach ($article->querySelectorAll('.openGallery, .openGalleryMobile') as $element) {
            $parent = $element->parentElement;
            if ($parent !== null && $parent->tagName === 'a') {
                $parent->remove();
            } else {
                $element->remove();
            }
        }

        foreach ($article->querySelectorAll('script, style, .ad, .banner, iframe, .typo-hint, .relatedbox, .ps-promo, .commentsBlock, .meta, .navigation, .corners, .newsHeading, .article-teaser') as $element) {
            $element->remove();
        }

        $content = (string)($article->innerHTML ?? '');
        $content = preg_replace('/#([^\s#<]+)\s*/u', '', $content);
        $content = $this->applyStyles($content);

        $result['content'] = $content;
        $result['tags'] = $tags;
        $result['author'] = $author;
        $result['date'] = $date;

        return $result;
    }

    private function cleanRssDescription(string $description): string
    {
        $description = preg_replace('/#([^\s#<]+)\s*/u', '', $description);
        return trim($description);
    }

    private function processRssDescription(string $description): string
    {
        return $this->applyStyles($description);
    }

    public function collectData(): void
    {
        $limitInput = $this->getInput('limit');
        $limit = (is_numeric($limitInput) === true) ? (int)$limitInput : self::DEFAULT_LIMIT;

        $fullContentInput = $this->getInput('full_content');
        $fullContent = $fullContentInput === true || (is_string($fullContentInput) === true && $fullContentInput !== '');

        $rss = $this->fetchRss();

        if (isset($rss->channel->item) === false) {
            throw new \Exception('No items found in RSS feed');
        }

        $count = 0;
        foreach ($rss->channel->item as $item) {
            if ($count >= $limit) {
                break;
            }

            $title = (string)($item->title ?? '');
            $guid = (string)($item->guid ?? '');
            $link = $this->extractUrlFromGuid($guid);
            $description = (string)($item->description ?? '');
            $pubDate = strtotime((string)($item->pubDate ?? ''));

            $tags = $this->extractHashtags($description);
            $cleanDescription = $this->cleanRssDescription($description);
            $content = '';
            $author = '';

            if ($fullContent === true && $link !== '') {
                $fullContentData = $this->fetchFullContent($link);
                if ($fullContentData['content'] !== '') {
                    $content = $fullContentData['content'];
                }
                if ($fullContentData['tags'] !== []) {
                    $tags = $fullContentData['tags'];
                }
                $author = $fullContentData['author'];
                if ($fullContentData['date'] !== '') {
                    $parsedDate = strtotime($fullContentData['date']);
                    if ($parsedDate !== false) {
                        $pubDate = $parsedDate;
                    }
                }
            }

            if ($content === '') {
                $content = $this->processRssDescription($cleanDescription);
            }

            $this->items[] = [
                'title' => $title,
                'uri' => $link,
                'content' => $content,
                'timestamp' => ($pubDate !== false) ? $pubDate : time(),
                'uid' => hash('sha256', $link),
                'categories' => $tags,
                'author' => $author,
            ];

            $count++;
        }
    }
}
