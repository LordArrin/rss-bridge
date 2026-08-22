<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

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

    protected function parseItem($item)
    {
        $html = getSimpleHTMLDOMCached($item['uri']);
        if ($html === false) {
            $item['content'] .= '<br /><p><em>Could not request ' . $this->getName() . ': ' . $item['uri'] . '</em></p>';
            return $item;
        }

        $html = $html->find('.article-page', 0);
        $content_html = $html->find('.article-body', 0);

        foreach ($content_html->find('blockquote') as $blockquote) {
            if (str_starts_with(trim($blockquote->plaintext), 'Connect with us on') === true) {
                $blockquote->outertext = '';
            }
        }

        $content = $content_html->innertext;
        $subtitle = $html->find('.sub-title', 0);
        if ($subtitle !== null) {
            $content = '<p><b>' . $subtitle->plaintext . '</b></p>' . $content;
        }

        $author = $html->find('.article-author', 0);
        if ($author !== null && isset($item['author']) === false) {
            $item['author'] = trim($author->plaintext);
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
