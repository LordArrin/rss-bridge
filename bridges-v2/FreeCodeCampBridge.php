<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class FreeCodeCampBridge extends FeedExpander
{
    public const NAME = 'FreeCodecamp';
    public const URI = 'https://www.freecodecamp.org';
    public const DESCRIPTION = 'RSS feed for FreeCodeCamp';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const JUNK_SELECTORS = [
        'script',
        'hr',
        '.sidebar',
        '.post-full-author-header',
        '.author-card',
        '.learn-cta-row',
        '.social-row',
        '.partner-courses-banner',
        '.partner-courses',
        '.site-footer',
    ];

    public function collectData(): void
    {
        $this->collectExpandableDatas('https://www.freecodecamp.org/news/rss/', 15);
    }

    protected function parseItem(array $item): array
    {
        $uri = $item['uri'] ?? null;
        if (is_string($uri) === false || $uri === '') {
            return $item;
        }

        try {
            $html = getContents($uri);
        } catch (\Exception) {
            return $item;
        }

        if ($html === '') {
            return $item;
        }

        try {
            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString($html);
            libxml_use_internal_errors(false);
        } catch (\Throwable) {
            return $item;
        }

        // Extract author BEFORE removing author-related blocks
        $authorElement = $dom->querySelector('.post-full-author-header');
        if ($authorElement === null) {
            $authorElement = $dom->querySelector('.author-card');
        }

        if ($authorElement !== null) {
            $authorText = trim($authorElement->textContent ?? '');
            if ($authorText !== '') {
                $item['author'] = $authorText;
            }
        }

        // Remove junk blocks (including scripts and hr)
        foreach (self::JUNK_SELECTORS as $selector) {
            foreach (iterator_to_array($dom->querySelectorAll($selector)) as $junk) {
                $junk->remove();
            }
        }

        // Remove dangerous links (file:// protocol)
        foreach ($dom->querySelectorAll('a[href]') as $link) {
            $href = $link->getAttribute('href');
            if ($href !== null && str_starts_with($href, 'file://') === true) {
                $link->remove();
            }
        }

        // Extract featured image
        $content = '';
        $figureImg = $dom->querySelector('figure img');
        if ($figureImg !== null) {
            $imgSrc = $figureImg->getAttribute('src');
            if ($imgSrc !== null && $imgSrc !== '') {
                $content .= '<p><img src="' . htmlspecialchars($imgSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt=""></p>';
            }
        }

        // Extract article content
        $articleContent = $dom->querySelector('.post-full-content');
        if ($articleContent === null) {
            $articleContent = $dom->querySelector('.post-content');
        }

        if ($articleContent !== null) {
            $contentHtml = $dom->saveHtml($articleContent);
            $content .= $contentHtml;
        }

        if ($content !== '') {
            $item['content'] = $content;
        }

        return $item;
    }
}
