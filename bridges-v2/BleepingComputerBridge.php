<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class BleepingComputerBridge extends FeedExpander
{
    public const NAME = 'Bleeping Computer';
    public const URI = 'https://www.bleepingcomputer.com/';
    public const DESCRIPTION = 'Returns the newest articles.';
    public const MAINTAINER = 'No maintainer';

    public const CACHE_TIMEOUT = 3600;

    /**
     * Sponsored/advertiser domains promoted inside the article body.
     * Mirrors the uBlock cosmetic filters for this site.
     */
    private const SPONSOR_DOMAINS = [
        'wiz.io',
        'threatlocker.com',
        'adaptivesecurity.com',
        'kaseya.com',
    ];

    /**
     * CSS selectors for ad slots and promotional widgets.
     */
    private const AD_SELECTORS = [
        'div[class*="cz-related-article-wrapp"]',
        '.cz-post-comment-wrapp',
        '.cz-news-like-wrapp',
        '.article-callout',
        '.addthis_toolbox',
        '.addthis_default_style',
        '.addthis_32x32_style',
        '.addthis_button_twitter',
        '.addthis_button_linkedin',
        '.addthis_button_facebook',
        '.twitter',
        '.bc_social_icons',
        '.bc_goto_top',
        '[title="Back to Top"]',
        '.spon_res',
        '[data-freestar-ad]',
        'ins.adsbygoogle',
        '#wibble',
        'a[href^="https://www.wiz.io/"]',
        'a[href^="https://www.threatlocker.com/"]',
        'a[href^="https://www.adaptivesecurity.com/"]',
        'a[href^="https://www.kaseya.com/lp/"]',
        '.fl_ad',
    ];

    public function collectData(): void
    {
        $feed = self::URI . 'feed/';
        $this->collectExpandableDatas($feed);
    }

    protected function parseItem(array $item): array
    {
        // Random delay between 1-5 seconds to avoid triggering anti-bot protection.
        $delayMs = random_int(1000, 5000);
        usleep($delayMs * 1000);

        $existingContent = $item['content'] ?? '';
        if (is_string($existingContent) === false) {
            $existingContent = '';
        }

        $uri = $item['uri'] ?? null;
        if (is_string($uri) === false || $uri === '') {
            $item['content'] = $existingContent . '<p><em>Could not request ' . $this->getName() . ': missing article URI</em></p>';
            return $item;
        }

        $html = getContents($uri);
        if ($html === '') {
            $item['content'] = $existingContent . '<p><em>Could not request ' . $this->getName() . ': ' . $uri . '</em></p>';
            return $item;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $articleBody = $dom->querySelector('div.articleBody');
        if ($articleBody === null) {
            $item['content'] = $existingContent . '<p><em>Could not extract article content from ' . $uri . '</em></p>';
            return $item;
        }

        $this->removeJunk($articleBody);
        $this->unwrapFigureDivs($articleBody);
        $this->unwrapAdDivs($articleBody);
        $this->removeEmptyDivs($articleBody);
        $this->normalizeMedia($articleBody);

        $item['content'] = trim($dom->saveHtml($articleBody));

        return $item;
    }

    /**
     * Removes inline assets (style/script/noscript) and promotional widgets.
     */
    private function removeJunk(\Dom\Element $articleBody): void
    {
        $inlineSelectors = ['style', 'script', 'noscript'];
        foreach ($inlineSelectors as $selector) {
            foreach (iterator_to_array($articleBody->querySelectorAll($selector)) as $junk) {
                $junk->remove();
            }
        }

        foreach (self::AD_SELECTORS as $selector) {
            foreach (iterator_to_array($articleBody->querySelectorAll($selector)) as $junk) {
                $junk->remove();
            }
        }

        // uBlock: ##.bc-heading:has-text(ponsor Posts) and the <ul> right after it.
        foreach (iterator_to_array($articleBody->querySelectorAll('.bc-heading')) as $heading) {
            $text = strtolower($heading->textContent ?? '');
            if (str_contains($text, 'sponsor posts') === false) {
                continue;
            }
            $next = $heading->nextElementSibling;
            if ($next !== null && $next->localName === 'ul') {
                $next->remove();
            }
            $heading->remove();
        }
    }

    /**
     * Lifts <figure> elements out of their parent <div> wrappers, preserving
     * the figure with its <figcaption>, then removes the empty div.
     */
    private function unwrapFigureDivs(\Dom\Element $articleBody): void
    {
        $children = iterator_to_array($articleBody->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \Dom\Element === false || $child->localName !== 'div') {
                continue;
            }

            $figures = iterator_to_array($child->querySelectorAll('figure'));
            if ($figures === []) {
                continue;
            }

            foreach ($figures as $figure) {
                $articleBody->insertBefore($figure, $child);
            }

            $child->remove();
        }
    }

    /**
     * uBlock:
     *   ##.articleBody > div[style^="width:"]:not([class]):has(> a[target="_blank"])
     *   ##.articleBody > div:has(a[href^="https://www.wiz.io/"])
     *
     * Images inside those wrappers are lifted out first so real content
     * pictures survive, then the wrapper itself is dropped.
     */
    private function unwrapAdDivs(\Dom\Element $articleBody): void
    {
        $children = iterator_to_array($articleBody->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \Dom\Element === false || $child->localName !== 'div') {
                continue;
            }

            $hasSponsorLink = false;
            foreach (self::SPONSOR_DOMAINS as $domain) {
                if ($child->querySelector('a[href*="' . $domain . '"]') !== null) {
                    $hasSponsorLink = true;
                    break;
                }
            }

            $style = $child->getAttribute('style') ?? '';
            $hasClass = ($child->getAttribute('class') ?? '') !== '';
            $hasBlankTargetLink = $child->querySelector('a[target="_blank"]') !== null;
            $isAdWidthBox = str_starts_with($style, 'width:') === true
                && $hasClass === false
                && $hasBlankTargetLink === true;

            if ($hasSponsorLink === false && $isAdWidthBox === false) {
                continue;
            }

            foreach (iterator_to_array($child->querySelectorAll('img')) as $img) {
                $articleBody->insertBefore($img, $child);
            }

            $child->remove();
        }
    }

    /**
     * Removes div elements that are empty after all other cleanup.
     */
    private function removeEmptyDivs(\Dom\Element $articleBody): void
    {
        $divs = iterator_to_array($articleBody->querySelectorAll('div'));

        foreach ($divs as $div) {
            $text = trim($div->textContent ?? '');
            if ($text === '' && $div->querySelector('img, figure, video, iframe') === null) {
                $div->remove();
            }
        }
    }

    /**
     * The site lazy-loads images via blazy.js: src holds a 1x1 transparent GIF
     * and the real URL lives in data-src / srcset. Restore it, unwrap <picture>,
     * and strip the responsive/lazy attributes feed readers cannot handle.
     */
    private function normalizeMedia(\Dom\Element $articleBody): void
    {
        foreach (iterator_to_array($articleBody->querySelectorAll('picture')) as $picture) {
            $parent = $picture->parentNode;
            if ($parent === null) {
                continue;
            }
            $img = $picture->querySelector('img');
            if ($img !== null) {
                $parent->insertBefore($img, $picture);
            }
            $picture->remove();
        }

        foreach ($articleBody->querySelectorAll('img') as $img) {
            $this->restoreLazyImage($img);

            foreach (['srcset', 'sizes', 'loading', 'onload', 'data-src', 'data-srcset', 'data-original', 'data-lazy-src', 'data-cfsrc', 'data-src-retina'] as $attr) {
                if ($img->hasAttribute($attr) === true) {
                    $img->removeAttribute($attr);
                }
            }

            $img->setAttribute('style', 'max-width:100%;height:auto;');
        }
    }

    private function restoreLazyImage(\Dom\Element $img): void
    {
        $src = $img->getAttribute('src') ?? '';
        if ($src !== '' && str_starts_with($src, 'data:') === false) {
            return;
        }

        foreach (['data-src', 'data-original', 'data-lazy-src', 'data-cfsrc'] as $attr) {
            $candidate = $img->getAttribute($attr);
            if (
                $candidate !== null
                && $candidate !== ''
                && str_starts_with($candidate, 'data:') === false
            ) {
                $img->setAttribute('src', $candidate);
                return;
            }
        }

        foreach (['data-srcset', 'srcset'] as $attr) {
            $candidate = $img->getAttribute($attr);
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $url = self::bestSrcsetUrl($candidate);
            if ($url !== null) {
                $img->setAttribute('src', $url);
                return;
            }
        }

        // No real URL recoverable: drop the placeholder entirely.
        if ($src === '' || str_starts_with($src, 'data:') === true) {
            $img->remove();
        }
    }

    private static function bestSrcsetUrl(string $srcset): ?string
    {
        $best = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            if ($parts === false || $parts === [] || $parts[0] === '') {
                continue;
            }

            $url = $parts[0];
            if (str_starts_with($url, 'data:') === true) {
                continue;
            }

            $width = -1;
            if (isset($parts[1]) === true && preg_match('/^(\d+)w$/', $parts[1], $m) === 1) {
                $width = (int) $m[1];
            }

            if ($width >= $bestWidth) {
                $bestWidth = $width;
                $best = $url;
            }
        }

        return $best;
    }
}
