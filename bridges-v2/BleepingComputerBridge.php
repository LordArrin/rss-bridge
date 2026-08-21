<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class BleepingComputerBridge extends FeedExpander
{
    public const NAME = 'Bleeping Computer';
    public const URI = 'https://www.bleepingcomputer.com/';
    public const DESCRIPTION = 'Returns the newest articles.';
    public const MAINTAINER = 'no maintainer';

    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $feed = self::URI . 'feed/';
        $this->collectExpandableDatas($feed);
    }

    protected function parseItem($item): array
    {
        $html = getContents($item['uri']);
        if ($html === '') {
            $item['content'] = ($item['content'] ?? '') . '<p><em>Could not request ' . $this->getName() . ': ' . ($item['uri'] ?? '') . '</em></p>';
            return $item;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $articleBody = $dom->querySelector('div.articleBody');
        if ($articleBody === null) {
            $item['content'] = ($item['content'] ?? '') . '<p><em>Could not extract article content from ' . ($item['uri'] ?? '') . '</em></p>';
            return $item;
        }

        $relatedSections = $articleBody->querySelectorAll('div[class*="cz-related-article-wrapp"]');
        foreach ($relatedSections as $section) {
            $section->remove();
        }

        $callouts = $articleBody->querySelectorAll('.article-callout');
        foreach ($callouts as $callout) {
            $callout->remove();
        }

        $itemContentDivs = $articleBody->querySelectorAll('.item-content > div');
        foreach ($itemContentDivs as $div) {
            $bleepLinks = $div->querySelectorAll('a[href*="bleepstatic.com"]');
            if (iterator_count($bleepLinks) > 0) {
                $div->remove();
            }
        }

        $bleepImages = $articleBody->querySelectorAll('img[src*="bleepstatic.com"]');
        foreach ($bleepImages as $img) {
            $parent = $img->parentElement;
            if ($parent !== null && $parent->tagName === 'a') {
                $grandparent = $parent->parentElement;
                if ($grandparent !== null && $grandparent->tagName === 'div') {
                    $grandparent->remove();
                    continue;
                }
                $parent->remove();
                continue;
            }
            $imgParent = $img->parentElement;
            if ($imgParent !== null && $imgParent->tagName === 'div') {
                $imgParent->remove();
            }
        }

        $item['content'] = trim($dom->saveHTML($articleBody));

        return $item;
    }
}
