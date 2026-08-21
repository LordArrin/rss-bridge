<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class TheGuardianBridge extends FeedExpander
{
    public const NAME = 'The Guardian';
    public const URI = 'https://www.theguardian.com/';
    public const DESCRIPTION = 'RSS feed for The Guardian';
    public const MAINTAINER = 'no maintainer';
    public const CACHE_TIMEOUT = 1200;
    public const PARAMETERS = [[
        'feed' => [
            'name' => 'Feed',
            'type' => 'list',
            'values' => [
                'World News' => 'world/rss',
                'US News' => '/us-news/rss',
                'UK News' => '/uk-news/rss',
                'Australia News' => '/australia-news/rss',
                'Europe News' => '/world/europe-news/rss',
                'Asia News' => '/world/asia/rss',
                'Tech' => '/uk/technology/rss',
                'Business News' => '/uk/business/rss',
                'Opinion' => '/uk/commentisfree/rss',
                'Lifestyle' => '/uk/lifeandstyle/rss',
                'Culture' => '/uk/culture/rss',
                'Sports' => '/uk/sport/rss'
            ]
        ]
    ]];

    private const JUNK_SELECTORS = [
        '[class*="gu-island"]',
        '.open-lightbox',
        '#sign-in-gate',
        'figure[data-spacefinder-role="richLink"]',
        'figure[data-spacefinder-type="model.dotcomrendering.pageElements.NewsletterSignupBlockElement"]',
        '.dcr-76akua',
    ];

    public function collectData(): void
    {
        $feed = $this->getInput('feed');
        $url = 'https://feeds.theguardian.com/theguardian/' . $feed;
        $this->collectExpandableDatas($url, 10);
    }

    protected function parseItem($item)
    {
        $articlePage = getSimpleHTMLDOM($item['uri']);
        if ($articlePage === false) {
            $item['content'] .= '<br><p><em>Could not request ' . $this->getName() . ': ' . $item['uri'] . '</em></p>';
            return $item;
        }

        $content = '';

        $mainFigure = $articlePage->find('div[data-gu-name="media"] figure', 0);
        if ($mainFigure === null) {
            $mainFigure = $articlePage->find('figure[id]', 0);
        }
        if ($mainFigure !== null) {
            $content .= $this->extractImageFromFigure($mainFigure);
        }

        $standfirst = $articlePage->find('div[data-gu-name="standfirst"]', 0);
        if ($standfirst !== null) {
            $inner = $standfirst->find('div', 0);
            if ($inner !== null) {
                $content .= '<div><em>' . $inner->innertext . '</em></div>';
            } else {
                $standfirstText = trim($standfirst->plaintext);
                if ($standfirstText !== '') {
                    $content .= '<div><em>' . htmlspecialchars($standfirstText, ENT_QUOTES, 'UTF-8') . '</em></div>';
                }
            }
        }

        $body = $articlePage->find('.article-body-commercial-selector', 0);
        if ($body !== null) {
            $content .= $this->cleanBodyContent($body);
        }

        if ($content === '') {
            $item['content'] .= '<br><p><em>Could not extract article content from ' . $item['uri'] . '</em></p>';
            return $item;
        }

        $content = $this->removeJunkFromContent($content);

        $item['content'] = $content;

        $author = $articlePage->find('address[data-component="meta-byline"] a[rel="author"]', 0);
        if ($author !== null) {
            $item['author'] = trim($author->plaintext);
        }

        $categories = $this->extractTags($articlePage);
        if ($categories !== []) {
            $item['categories'] = $categories;
        }

        return $item;
    }

    private function extractImageFromFigure(\simple_html_dom_node $figure): string
    {
        $spacefinderRole = $figure->getAttribute('data-spacefinder-role');
        $spacefinderType = (string) $figure->getAttribute('data-spacefinder-type');

        if ($spacefinderRole === 'richLink' || str_contains($spacefinderType, 'NewsletterSignup') === true) {
            return '';
        }

        $picture = $figure->find('picture', 0);
        if ($picture === null) {
            return '';
        }

        $html = '<figure>' . $picture->outertext;

        $figcaption = $figure->find('figcaption', 0);
        if ($figcaption !== null) {
            $captionText = trim($figcaption->plaintext);
            if ($captionText !== '') {
                $html .= '<figcaption>' . htmlspecialchars($captionText, ENT_QUOTES, 'UTF-8') . '</figcaption>';
            }
        }

        return $html . '</figure>';
    }

    private function cleanBodyContent(\simple_html_dom_node $body): string
    {
        foreach (self::JUNK_SELECTORS as $selector) {
            foreach ($body->find($selector) as $el) {
                $el->outertext = '';
            }
        }

        foreach ($body->find('figure[id]') as $figure) {
            $figure->outertext = $this->extractImageFromFigure($figure);
        }

        $html = $body->innertext;

        $html = preg_replace('/<em>\s*The Associated Press contributed reporting\s*<\/em>/i', '', $html);
        $html = preg_replace('/<p[^>]*>\s*<em>\s*The Associated Press contributed reporting\s*<\/em>\s*<\/p>/i', '', $html);
        $html = str_ireplace('The Associated Press contributed reporting', '', $html);

        return $html;
    }

    private function removeJunkFromContent(string $content): string
    {
        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($content);
        libxml_use_internal_errors(false);

        foreach (self::JUNK_SELECTORS as $selector) {
            $elements = $dom->querySelectorAll($selector);
            foreach ($elements as $el) {
                $el->remove();
            }
        }

        $result = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }

    private function extractTags(\simple_html_dom_node $articlePage): array
    {
        foreach ($articlePage->find('meta') as $meta) {
            if ($meta->getAttribute('property') === 'article:tag') {
                $tags = [];
                foreach (explode(',', (string)$meta->getAttribute('content')) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') {
                        $tags[] = $tag;
                    }
                }
                if ($tags !== []) {
                    return $tags;
                }
            }
        }

        $tags = [];
        foreach ($articlePage->find('.dcr-p7nd18 li') as $tagEl) {
            $tagText = trim($tagEl->plaintext);
            if ($tagText !== '') {
                $tags[] = $tagText;
            }
        }
        return $tags;
    }
}
