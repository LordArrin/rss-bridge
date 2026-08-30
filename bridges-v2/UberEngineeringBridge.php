<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class UberEngineeringBridge extends BridgeAbstract
{
    public const NAME = 'Uber Engineering';
    public const URI = 'https://www.uber.com/us/en/blog/engineering/';
    public const DESCRIPTION = 'Returns posts from the Uber Engineering blog';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [];

    public const ARTICLE_FEED_PATH = '/us/en/blog/engineering/';

    private const CSS = [
        'image' => 'max-width: 800px; width: auto; height: auto;',
    ];

    public function collectData(): void
    {
        $html = getContents(self::URI);

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from Uber Engineering page');
        }

        $articles = self::extractArticleFeedFromHtml($html, self::ARTICLE_FEED_PATH);

        foreach ($articles as $article) {
            if (is_array($article) === false) {
                continue;
            }

            $title = trim((string) ($article['title'] ?? ''));
            $uri = self::normalizeUrl((string) ($article['fullURL'] ?? ''));

            if ($title === '' || $uri === '') {
                continue;
            }

            $item = [];
            $item['title'] = self::decode($title);
            $item['uri'] = $uri;

            if (isset($article['publishedAt']) === true && is_string($article['publishedAt']) === true && $article['publishedAt'] !== '') {
                $timestamp = strtotime($article['publishedAt']);
                if ($timestamp !== false) {
                    $item['timestamp'] = $timestamp;
                }
            }

            $ogImageURL = (string) ($article['ogImageURL'] ?? '');
            $content = $this->buildItemContent($uri, $ogImageURL);
            if ($content !== '') {
                $item['content'] = $content;
            }

            $this->items[] = $item;
        }
    }

    private static function extractArticleFeedFromHtml(string $html, string $path): array
    {
        $scriptId = '__LOCAL_REDUX_STATE_Newsroom_Article Feed Store_' . rawurlencode($path) . '__';
        $pattern = '#<script type="application/json" id="' . preg_quote($scriptId, '#') . '">\s*(.*?)\s*</script>#s';

        if (preg_match($pattern, $html, $matches) !== 1) {
            \throwServerException('Unable to find article feed data');
        }

        $payload = rawurldecode(self::decode((string) $matches[1]));
        $data = \Json::decode($payload);

        if (is_array($data) === false) {
            \throwServerException('Unable to parse article feed data');
        }

        $articles = $data['relatedPages']['relatedPages'] ?? null;

        if (is_array($articles) === false) {
            \throwServerException('Unable to parse article feed data');
        }

        return $articles;
    }

    private static function normalizeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (strpos($url, 'https://') === 0 || strpos($url, 'http://') === 0) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        if (strpos($url, 'www.') === 0) {
            return 'https://' . $url;
        }

        if (strpos($url, '/') === 0) {
            return 'https://www.uber.com' . $url;
        }

        return 'https://www.uber.com/' . ltrim($url, '/');
    }

    private function buildItemContent(string $uri, string $imageUrl): string
    {
        $content = '';

        if ($imageUrl !== '') {
            $content .= '<p><img src="' . htmlspecialchars($imageUrl, ENT_QUOTES | ENT_HTML5) . '" style="' . self::CSS['image'] . '" alt="" /></p>';
        }

        $description = $this->fetchArticleDescription($uri);
        if ($description !== '') {
            $content .= '<p>' . htmlspecialchars($description, ENT_QUOTES | ENT_HTML5) . '</p>';
        }

        return $content;
    }

    private function fetchArticleDescription(string $uri): string
    {
        try {
            $html = getContents($uri);
        } catch (\Exception $e) {
            return '';
        }

        if (is_string($html) === false || $html === '') {
            return '';
        }

        try {
            $dom = \Dom\HTMLDocument::createFromString($html);
        } catch (\Exception $e) {
            return '';
        }

        $description = $dom->querySelector('meta[name="description"], meta[property="og:description"]');
        if ($description === null) {
            return '';
        }

        $content = $description->getAttribute('content');
        if (is_string($content) === false || $content === '') {
            return '';
        }

        return self::decode($content);
    }

    private static function decode(string $s): string
    {
        $s = trim($s);
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
    }
}
