<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class WeLiveSecurityBridge extends FeedExpander
{
    public const NAME = 'We Live Security';
    public const URI = 'https://www.welivesecurity.com/';
    public const DESCRIPTION = 'Returns the newest articles.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'limit' => self::LIMIT,
        ],
    ];

    protected function parseItem(array $item): array|false
    {
        try {
            $dom = getSimpleHTMLDOMCached($item['uri']);
        } catch (\Throwable $e) {
            $item['content'] = ($item['content'] ?? '') . '<br /><p><em>Could not request ' . $this->getName() . ': ' . $item['uri'] . '</em></p>';
            return $item;
        }

        $articlePage = $dom->querySelector('.article-page');
        if ($articlePage === null) {
            $item['content'] = ($item['content'] ?? '') . '<br /><p><em>Could not find article page: ' . $item['uri'] . '</em></p>';
            return $item;
        }

        $contentHtml = $articlePage->querySelector('.article-body');
        if ($contentHtml === null) {
            $item['content'] = ($item['content'] ?? '') . '<br /><p><em>Could not find article body: ' . $item['uri'] . '</em></p>';
            return $item;
        }

        // Remove promotional blockquotes
        foreach ($contentHtml->querySelectorAll('blockquote') as $blockquote) {
            $text = trim($blockquote->textContent ?? '');
            if (str_starts_with($text, 'Connect with us on') === true) {
                $blockquote->remove();
            }
        }

        $content = $contentHtml->innerHTML ?? '';

        $subtitle = $articlePage->querySelector('.sub-title');
        if ($subtitle !== null) {
            $subtitleText = trim($subtitle->textContent ?? '');
            if ($subtitleText !== '') {
                $content = '<p><b>' . htmlspecialchars($subtitleText, ENT_QUOTES, 'UTF-8') . '</b></p>' . $content;
            }
        }

        $author = $articlePage->querySelector('.article-author');
        if ($author !== null && isset($item['author']) === false) {
            $authorText = trim($author->textContent ?? '');
            if ($authorText !== '') {
                $item['author'] = $authorText;
            }
        }

        $item['content'] = trim($content);
        return $item;
    }

    public function collectData(): void
    {
        $feed = static::URI . 'feed/';
        $limit = $this->getInput('limit') ?? 10;
        $this->collectExpandableDatas($feed, $limit);
    }
}
