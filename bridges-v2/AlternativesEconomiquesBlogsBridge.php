<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use Dom\HTMLDocument;
use Dom\Element;
use Dom\Node;
use RSSBridge\BridgeAbstract;
use RSSBridge\Http\HttpException;

use function RSSBridge\Exceptions\throwClientException;
use function RSSBridge\Exceptions\throwServerException;

final class AlternativesEconomiquesBlogsBridge extends BridgeAbstract
{
    public const NAME = "Les blogs d'Alternatives Economiques";
    public const URI = 'https://blogs.alternatives-economiques.fr/';
    public const DESCRIPTION = 'Returns the newest articles';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 4800;

    public const FAVICON = 'https://www.alternatives-economiques.fr/themes/custom/front/favicon.ico';

    public const PARAMETERS = [
        [
            'blogname' => [
                'name' => 'Blog Name',
                'type' => 'text',
                'exampleValue' => 'eco-presidentielle',
                'required' => true,
            ],
        ],
    ];

    public function getName(): string
    {
        $blogname = $this->getInput('blogname');
        if (is_string($blogname) === true && $blogname !== '') {
            return $blogname . ' - ' . self::NAME;
        }
        return parent::getName();
    }

    public function getURI(): string
    {
        $blogname = $this->getInput('blogname');
        if (is_string($blogname) === true && $blogname !== '') {
            return self::URI . urlencode($blogname) . '/';
        }
        return self::URI;
    }

    public function getIcon(): string
    {
        return self::FAVICON;
    }

    public function collectData(): void
    {
        $blogname = (string) ($this->getInput('blogname') ?? '');
        if ($blogname === '') {
            throwClientException('Blog name is required');
        }

        $baseUrl = self::URI . urlencode($blogname) . '/';

        try {
            $html = getContents($baseUrl);
        } catch (HttpException $e) {
            throwServerException('Failed to fetch blog page: ' . $e->getMessage());
        }

        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $articles = $dom->querySelectorAll('article');

        foreach ($articles as $article) {
            $item = $this->processArticle($article, $baseUrl);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }
    }

    private function processArticle(Element $article, string $baseUrl): ?array
    {
        $titleElement = $article->querySelector('h2');
        if ($titleElement === null) {
            return null;
        }
        $title = trim($titleElement->textContent ?? '');
        if ($title === '') {
            return null;
        }

        $linkElement = $article->querySelector('a');
        $href = $linkElement?->getAttribute('href') ?? '';
        if ($href === '') {
            return null;
        }
        $url = $this->resolveUrl($baseUrl, $href);

        $timeElement = $article->querySelector('time');
        $timestamp = time();
        if ($timeElement !== null) {
            $dateStr = trim($timeElement->textContent ?? '');
            $publishedDate = \DateTime::createFromFormat('d/m/Y', $dateStr);
            if ($publishedDate !== false) {
                $publishedDate->setTime(0, 0, 0, 0);
                $timestamp = $publishedDate->getTimestamp();
            }
        }

        $authorElement = $article->querySelector('.o-infos__author');
        $author = '';
        if ($authorElement !== null) {
            $author = trim($authorElement->textContent ?? '');
        }

        $contentHtml = '<meta name="referrer" content="no-referrer">';
        try {
            $articleHtml = getContents($url);
            $articleDom = HTMLDocument::createFromString($articleHtml, LIBXML_NOERROR);

            $this->fixRelativeUrls($articleDom, $url);

            $contentNode = $articleDom->querySelector('.c-blogs-post__body');
            if ($contentNode !== null) {
                $contentHtml .= $this->getInnerHtml($contentNode, $articleDom);
            }
        } catch (HttpException $e) {
            $contentHtml .= '<p>Failed to load article content.</p>';
        }

        return [
            'uri' => $url,
            'title' => $title,
            'author' => $author,
            'timestamp' => $timestamp,
            'content' => $contentHtml,
        ];
    }

    private function fixRelativeUrls(HTMLDocument $dom, string $baseUrl): void
    {
        $elements = $dom->querySelectorAll('img, a, video, source, iframe');
        foreach ($elements as $element) {
            $src = $element->getAttribute('src');
            if ($src !== null && $src !== '') {
                $element->setAttribute('src', $this->resolveUrl($baseUrl, $src));
            }

            $href = $element->getAttribute('href');
            if ($href !== null && $href !== '') {
                $element->setAttribute('href', $this->resolveUrl($baseUrl, $href));
            }

            $poster = $element->getAttribute('poster');
            if ($poster !== null && $poster !== '') {
                $element->setAttribute('poster', $this->resolveUrl($baseUrl, $poster));
            }

            if ($element->tagName === 'img') {
                $dataSrc = $element->getAttribute('data-src');
                if ($dataSrc !== null && $dataSrc !== '') {
                    $currentSrc = $element->getAttribute('src');
                    if ($currentSrc === null || $currentSrc === '' || str_starts_with($currentSrc, 'data:') === true) {
                        $element->setAttribute('src', $this->resolveUrl($baseUrl, $dataSrc));
                    }
                }
            }
        }
    }

    private function getInnerHtml(Node $node, HTMLDocument $dom): string
    {
        $innerHTML = '';
        foreach ($node->childNodes as $child) {
            $innerHTML .= $dom->saveHTML($child);
        }
        return $innerHTML;
    }

    private function resolveUrl(string $baseUrl, string $url): string
    {
        if (str_starts_with($url, 'http://') === true || str_starts_with($url, 'https://') === true) {
            return $url;
        }
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';

        if (str_starts_with($url, '/') === true) {
            return $scheme . '://' . $host . $url;
        }

        $basePath = dirname($path);
        if ($basePath === '.') {
            $basePath = '/';
        }
        if (str_ends_with($basePath, '/') === false) {
            $basePath .= '/';
        }
        return $scheme . '://' . $host . $basePath . $url;
    }
}
