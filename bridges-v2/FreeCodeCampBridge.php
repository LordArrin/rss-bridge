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

    public function collectData(): void
    {
        $this->collectExpandableDatas('https://www.freecodecamp.org/news/rss/', 15);
    }

    protected function parseItem(array $item): array|false
    {
        $html = getContents($item['uri']);
        if ($html === '') {
            return $item;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $content = '';

        $figureImg = $dom->querySelector('figure img');
        if ($figureImg !== null) {
            $imgSrc = $figureImg->getAttribute('src');
            if ($imgSrc !== null) {
                $content .= '<p><img src="' . htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') . '" alt=""></p>';
            }
        }

        $articleContent = $dom->querySelector('.post-full-content');
        if ($articleContent === null) {
            $articleContent = $dom->querySelector('.post-content');
        }

        if ($articleContent !== null) {
            $contentHtml = $dom->saveHTML($articleContent);
            $content .= $contentHtml;
        }

        if ($content !== '') {
            $item['content'] = $content;
        }

        $authorElement = $dom->querySelector('.post-full-author-header');
        if ($authorElement === null) {
            $authorElement = $dom->querySelector('.author-card');
        }

        if ($authorElement !== null) {
            $authorText = trim($authorElement->textContent);
            if ($authorText !== '') {
                $item['author'] = $authorText;
            }
        }

        return $item;
    }
}
