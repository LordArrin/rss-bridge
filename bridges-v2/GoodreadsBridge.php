<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class GoodreadsBridge extends BridgeAbstract
{
    public const NAME = 'Goodreads';
    public const URI = 'https://www.goodreads.com/';
    public const DESCRIPTION = 'Various RSS feeds from Goodreads';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const CONTEXT_AUTHOR_BOOKS = 'Books by Author';

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 600px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        'Books by Author' => [
            'author_url' => [
                'name' => 'Link to author\'s page on Goodreads',
                'type' => 'text',
                'required' => true,
                'title' => 'Should look somewhat like goodreads.com/author/show/',
                'pattern' => '^(https:\/\/)?(www.)?goodreads\.com\/author\/show\/\d+\..*$',
                'exampleValue' => 'https://www.goodreads.com/author/show/38550.Brandon_Sanderson'
            ],
            'published_only' => [
                'name' => 'Show published books only',
                'type' => 'checkbox',
                'required' => false,
                'title' => 'If left unchecked, this will return unpublished books as well',
                'defaultValue' => 'checked',
            ],
        ],
    ];

    private function collectAuthorBooks(string $url): void
    {
        $regex = '/goodreads\.com\/author\/show\/(\d+)/';

        if (preg_match($regex, $url, $matches) !== 1) {
            \throwClientException('Invalid author URL format');
        }

        $authorId = (string) $matches[1];
        $authorListUrl = 'https://www.goodreads.com/author/list/' . $authorId . '?sort=original_publication_year';

        $html = getContents($authorListUrl);

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from Goodreads author page');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $rows = $dom->querySelectorAll('tr[itemtype="http://schema.org/Book"]');

        foreach ($rows as $row) {
            if ($row instanceof \Dom\Element === false) {
                continue;
            }

            $dateSpan = $row->querySelector('.uitext');
            $dateSpanText = ($dateSpan !== null) ? (string) $dateSpan->textContent : '';
            $date = null;

            if (preg_match('/published\s+(\d{4})/', $dateSpanText, $dateMatches) === 1) {
                $date = (string) $dateMatches[1] . '-01-01';
            } elseif ((string) ($this->getInput('published_only') ?? '') !== 'checked') {
                $date = date('Y-01-01');
            } else {
                continue;
            }

            $bookTitleNode = $row->querySelector('.bookTitle');
            if ($bookTitleNode === null) {
                continue;
            }

            $title = trim((string) $bookTitleNode->textContent);
            $href = (string) ($bookTitleNode->getAttribute('href') ?? '');

            if ($href !== '' && str_starts_with($href, '/') === true) {
                $href = rtrim(self::URI, '/') . $href;
            }

            $authorNameNode = $row->querySelector('.authorName');
            $authorName = ($authorNameNode !== null) ? trim((string) $authorNameNode->textContent) : '';

            $bookCoverNode = $row->querySelector('.bookCover');
            $coverSrc = ($bookCoverNode !== null) ? (string) ($bookCoverNode->getAttribute('src') ?? '') : '';
            $coverSrc = $this->normalizeImageUrl($coverSrc);

            $content = '';
            if ($coverSrc !== '') {
                $content .= '<a href="' . htmlspecialchars($href) . '">';
                $content .= '<img src="' . htmlspecialchars($coverSrc) . '" style="' . self::CSS['img'] . '" alt="" />';
                $content .= '</a>';
            }

            $item = [];
            $item['title'] = $title;
            $item['uri'] = $href;
            $item['author'] = $authorName;
            $item['content'] = $content;
            $item['uid'] = $href;

            $timestamp = strtotime($date);
            if ($timestamp !== false) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }

    private function normalizeImageUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $patterns = [
            '/_S[XY]\d+_?/',
            '/_U[XY]\d+_?/',
            '/\._S[X,Y]\d+_/',
            '/\._U[X,Y]\d+_/',
        ];

        $normalized = $url;
        foreach ($patterns as $pattern) {
            $normalized = preg_replace($pattern, '', $normalized);
            if (is_string($normalized) === false) {
                $normalized = $url;
                break;
            }
        }

        if ($normalized === '' || $normalized === null) {
            return $url;
        }

        return $normalized;
    }

    public function collectData(): void
    {
        $context = $this->queriedContext;

        if ($context === self::CONTEXT_AUTHOR_BOOKS) {
            $authorUrl = (string) ($this->getInput('author_url') ?? '');
            if ($authorUrl === '') {
                \throwClientException('Author URL is required');
            }
            $this->collectAuthorBooks($authorUrl);
        } else {
            \throwServerException('Invalid context');
        }
    }
}
