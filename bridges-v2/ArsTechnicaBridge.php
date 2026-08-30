<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class ArsTechnicaBridge extends FeedExpander
{
    public const NAME = 'Ars Technica';
    public const URI = 'https://arstechnica.com/';
    public const DESCRIPTION = 'Returns the latest articles from Ars Technica';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'section' => [
            'name' => 'Site section',
            'type' => 'list',
            'defaultValue' => 'index',
            'values' => [
                'All' => 'index',
                'Apple' => 'apple',
                'Board Games' => 'cardboard',
                'Cars' => 'cars',
                'Features' => 'features',
                'Gaming' => 'gaming',
                'Information Technology' => 'technology-lab',
                'Science' => 'science',
                'Staff Blogs' => 'staff-blogs',
                'Tech Policy' => 'tech-policy',
                'Tech' => 'gadgets',
            ]
        ]
    ]];

    public function collectData(): void
    {
        $section = (string)$this->getInput('section');
        $url = 'https://feeds.arstechnica.com/arstechnica/' . $section;
        $this->collectExpandableDatas($url, 10);
    }

    protected function parseItem($item): array
    {
        $html = getContents($item['uri']);
        if ($html === '') {
            return $item;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($dom);

        $content = '';
        $article = $dom->querySelector('article');
        $header = $article !== null ? $article->querySelector('header') : null;

        if ($header !== null) {
            $leading = $header->querySelector('p[class*="leading"]');
            if ($leading !== null) {
                $content .= '<p>' . $dom->saveHTML($leading) . '</p>';
            }

            $intro_image = $header->querySelector('img.intro-image');
            if ($intro_image !== null) {
                $content .= '<figure>' . $dom->saveHTML($intro_image);
                $image_caption = $header->querySelector('.caption .caption-content');
                if ($image_caption !== null) {
                    $content .= '<figcaption>' . $dom->saveHTML($image_caption) . '</figcaption>';
                }
                $content .= '</figure>';
            }
        }

        $postContentElements = $dom->querySelectorAll('.post-content');
        foreach ($postContentElements as $content_tag) {
            $content .= $dom->saveHTML($content_tag);
        }

        libxml_use_internal_errors(true);
        $contentDom = \Dom\HTMLDocument::createFromString('<div>' . $content . '</div>');
        libxml_use_internal_errors(false);

        $wrapper = $contentDom->querySelector('div');
        if ($wrapper === null) {
            return $item;
        }

        $parsely = $dom->querySelector('[name="parsely-page"]');
        $parselyJson = null;
        if ($parsely !== null) {
            $parselyContent = $parsely->getAttribute('content');
            if ($parselyContent !== null) {
                $decoded = html_entity_decode($parselyContent, ENT_QUOTES, 'UTF-8');
                $parsed = json_decode($decoded, true);
                if (is_array($parsed) === true) {
                    $parselyJson = $parsed;
                }
            }
        }

        if ($parselyJson !== null && isset($parselyJson['tags']) === true && is_array($parselyJson['tags']) === true) {
            $item['categories'] = $parselyJson['tags'];
        }

        $weirdLightboxes = $wrapper->querySelectorAll('figure div div.ars-lightbox');
        foreach ($weirdLightboxes as $weird_lightbox) {
            $parent = $weird_lightbox->parentElement;
            if ($parent !== null) {
                $grandparent = $parent->parentElement;
                if ($grandparent !== null) {
                    $grandparent->replaceWith($weird_lightbox);
                }
            }
        }

        $lightboxes = $wrapper->querySelectorAll('.ars-lightbox');
        foreach ($lightboxes as $lightbox) {
            $lightbox_content = '';
            $lightbox_items = $lightbox->querySelectorAll('.ars-lightbox-item');
            foreach ($lightbox_items as $lightbox_item) {
                $img = $lightbox_item->querySelector('img');
                if ($img !== null) {
                    $lightbox_content .= '<figure>' . $contentDom->saveHTML($img);
                    $caption = $lightbox_item->querySelector('div.pswp-caption-content');
                    if ($caption !== null) {
                        $credit = $lightbox_item->querySelector('div.ars-gallery-caption-credit');
                        if ($credit !== null) {
                            $creditText = $credit->textContent ?? '';
                            $credit->textContent = 'Credit: ' . $creditText;
                        }
                        $lightbox_content .= '<figcaption>' . $contentDom->saveHTML($caption) . '</figcaption>';
                    }
                    $lightbox_content .= '</figure>';
                }
            }
            $lightbox->innerHTML = $lightbox_content;
        }

        $interludes = $wrapper->querySelectorAll('.ars-interlude-container');
        foreach ($interludes as $ad) {
            $ad->remove();
        }

        $tocs = $wrapper->querySelectorAll('.toc-container');
        foreach ($tocs as $toc) {
            $toc->remove();
        }

        $iframes = $wrapper->querySelectorAll('iframe');
        foreach ($iframes as $iframe) {
            $src = $iframe->getAttribute('src') ?? '';
            if ($src !== '') {
                $replacement = $contentDom->createTextNode('');
                $a = $contentDom->createElement('a');
                $a->setAttribute('href', $src);
                $a->textContent = $src;
                $iframe->replaceWith($a);
            }
        }

        $styledDivs = $wrapper->querySelectorAll('div[style*="aspect-ratio"]');
        foreach ($styledDivs as $styled) {
            $styled->removeAttribute('style');
        }

        $this->backgroundToImg($wrapper);

        $result = '';
        foreach ($wrapper->childNodes as $child) {
            $result .= $contentDom->saveHTML($child);
        }

        $item['content'] = $result;

        if ($parselyJson !== null && isset($parselyJson['post_id']) === true) {
            $item['uid'] = (string)$parselyJson['post_id'];
        }

        return $item;
    }

    private function resolveRelativeUrls(\Dom\HTMLDocument $dom): void
    {
        $base = rtrim(self::URI, '/');
        $elements = $dom->querySelectorAll('[src], [href]');
        foreach ($elements as $el) {
            foreach (['src', 'href'] as $attr) {
                $value = $el->getAttribute($attr);
                if ($value === null) {
                    continue;
                }
                if (str_starts_with($value, '/') === true && str_starts_with($value, '//') === false) {
                    $el->setAttribute($attr, $base . $value);
                }
            }
        }
    }

    private function backgroundToImg(\Dom\Element $wrapper): void
    {
        $elements = $wrapper->querySelectorAll('[style]');
        foreach ($elements as $el) {
            $style = $el->getAttribute('style') ?? '';
            if (preg_match('/background(?:-image)?:\s*url\(["\']?([^"\')\s]+)["\']?\)/i', $style, $matches) === 1) {
                $url = $matches[1];
                $img = $wrapper->ownerDocument->createElement('img');
                $img->setAttribute('src', $url);
                $img->setAttribute('style', 'max-width:100%;height:auto;display:block');
                $el->replaceWith($img);
            }
        }
    }
}
