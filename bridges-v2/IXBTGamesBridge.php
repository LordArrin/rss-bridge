<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class IXBTGamesBridge extends BridgeAbstract
{
    public const NAME = 'IXBT Games';
    public const URI = 'https://ixbt.games/';
    public const DESCRIPTION = 'Gaming industry news from IXBT Games';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 1800;

    private const CSS_IMG = 'display: block; margin: 16px 0; max-width: 1600px; height: auto;';
    private const CSS_UL = 'list-style-type: disc; margin: 16px 0 16px 24px; padding: 0;';
    private const CSS_LI = 'margin: 8px 0;';
    private const CSS_TEXT = 'text-align: left;';

    public const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Post limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum number of news items to load',
            ],
            'digests_only' => [
                'name' => 'Digests only',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Download only digests (the week highlights)',
            ],
        ]
    ];

    public function collectData(): void
    {
        $digestsOnly = (bool)$this->getInput('digests_only');
        $limitInput = $this->getInput('limit');
        $limitValue = ($limitInput !== null) ? (int)$limitInput : 20;
        $limit = ($digestsOnly === true) ? 3 : $limitValue;

        $dom = getSimpleHTMLDOM('https://ixbt.games/news/');
        $newsContainer = $dom->querySelector('div.news');
        $cards = $newsContainer->querySelectorAll('div.card');
        $count = 0;

        foreach ($cards as $card) {
            if ($count >= $limit) {
                break;
            }

            $heading = $card->querySelector('h2');
            $link = $card->querySelector('a.publication-canonical-link');
            $img = $card->querySelector('img');
            $timeSpan = $card->querySelector('span');

            if ($heading === null || $link === null) {
                continue;
            }

            $title = trim($heading->textContent);
            $href = $link->getAttribute('href');
            $uri = (str_starts_with($href, 'http') === true) ? $href : self::URI . ltrim($href, '/');
            $previewImage = $this->buildPreviewImage($img, $title);
            $relativeTime = ($timeSpan !== null) ? trim($timeSpan->textContent) : '';
            $articleContent = $this->fetchArticleContent($uri, $previewImage);

            if ($digestsOnly === true && $this->isDigest($title, $articleContent) === false) {
                continue;
            }

            $timestamp = $this->parseRelativeTime($relativeTime);
            $this->items[] = [
                'title' => $title,
                'uri' => $uri,
                'content' => $articleContent,
                'timestamp' => $timestamp,
                'uid' => hash('sha256', $uri),
            ];
            $count++;
        }
    }

    private function buildPreviewImage(?\Dom\Element $img, string $title): string
    {
        if ($img === null) {
            return '';
        }

        $src = $img->getAttribute('src');
        if ($src === '') {
            return '';
        }

        return sprintf(
            '<p><img src="%s" style="%s" alt="%s" /></p>',
            htmlspecialchars($src, ENT_QUOTES),
            self::CSS_IMG,
            htmlspecialchars($title, ENT_QUOTES)
        );
    }

    private function isDigest(string $title, string $content): bool
    {
        $titleLower = mb_strtolower($title);
        $contentLower = mb_strtolower($content);

        if (mb_strpos($titleLower, 'самое интересное за неделю') !== false) {
            return true;
        }
        if (mb_strpos($titleLower, 'самые интересные материалы') !== false) {
            return true;
        }
        if (mb_strpos($titleLower, 'самые интересные материалы за неделю') !== false) {
            return true;
        }
        if (mb_strpos($contentLower, 'дайджест') !== false) {
            return true;
        }

        return false;
    }

    private function fetchArticleContent(string $uri, string $previewImage): string
    {
        $dom = getSimpleHTMLDOM($uri);
        $article = $dom->querySelector('article.pubcontainer');

        if ($article === null) {
            return $previewImage;
        }

        $proseContent = $article->querySelector('div.prose');
        if ($proseContent === null) {
            return $previewImage;
        }

        $this->extractImagesFromGalleries($proseContent);
        $this->processYouTubeEmbeds($proseContent);
        $this->removeUnwantedElements($proseContent);
        $this->normalizeText($proseContent);
        $this->processLists($proseContent);

        $content = $previewImage . $proseContent->innerHTML;
        $content = $this->processYouTubeIframes($content);
        $content = $this->processImages($content);

        return $content;
    }

    private function extractImagesFromGalleries(\Dom\Element $element): void
    {
        $galleries = $element->querySelectorAll('.content-block--gallery');
        foreach ($galleries as $gallery) {
            $images = $gallery->querySelectorAll('img');
            $galleryParent = $gallery->parentNode;

            if ($galleryParent === null) {
                continue;
            }

            foreach ($images as $img) {
                $galleryParent->insertBefore($img, $gallery);
            }

            $gallery->remove();
        }
    }

    private function processYouTubeEmbeds(\Dom\Element $element): void
    {
        $embeds = $element->querySelectorAll('.content-block--embed[data-service="youtube"]');
        foreach ($embeds as $embed) {
            $embedUrl = $embed->getAttribute('data-embed-url');
            if ($embedUrl === '') {
                $embed->remove();
                continue;
            }

            $videoId = $this->extractYouTubeVideoId($embedUrl);
            if ($videoId === null) {
                $embed->remove();
                continue;
            }

            $thumbnailUrl = "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg";
            $fallbackUrl = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
            $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
            $thumbnailData = @file_get_contents($thumbnailUrl);

            if ($thumbnailData === false || strlen($thumbnailData) < 100) {
                $thumbnailUrl = $fallbackUrl;
            }

            $html = $this->buildYouTubePreview($videoUrl, $thumbnailUrl);

            $parent = $embed->parentNode;
            if ($parent !== null) {
                $fragment = $parent->ownerDocument->createDocumentFragment();
                $fragment->appendXML($html);
                $parent->replaceChild($fragment, $embed);
            }
        }
    }

    private function buildYouTubePreview(string $videoUrl, string $thumbnailUrl): string
    {
        $imgStyle = 'display: block; margin: 0 0 8px 0; max-width: 1600px; height: auto;';
        $linkStyle = 'display: block; text-align: left; margin: 0 0 16px 0;';

        $videoUrlEscaped = htmlspecialchars($videoUrl, ENT_QUOTES);
        $thumbnailUrlEscaped = htmlspecialchars($thumbnailUrl, ENT_QUOTES);

        $html = "<p><a href=\"{$videoUrlEscaped}\" target=\"_blank\">";
        $html .= "<img src=\"{$thumbnailUrlEscaped}\" style=\"{$imgStyle}\" alt=\"YouTube Video\" /></a>";
        $html .= "<a href=\"{$videoUrlEscaped}\" target=\"_blank\" style=\"{$linkStyle}\">Watch video</a></p>";

        return $html;
    }

    private function extractYouTubeVideoId(string $url): ?string
    {
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function removeUnwantedElements(\Dom\Element $element): void
    {
        $unwantedSelectors = [
            '.read-more-box',
            'script',
            'style',
        ];

        foreach ($unwantedSelectors as $selector) {
            $elements = $element->querySelectorAll($selector);
            foreach ($elements as $el) {
                $el->remove();
            }
        }
    }

    private function normalizeText(\Dom\Element $element): void
    {
        $paragraphs = $element->querySelectorAll('p');
        foreach ($paragraphs as $p) {
            $this->normalizeNodeText($p);
            if (trim($p->textContent) === '') {
                $p->remove();
            }
        }
    }

    private function normalizeNodeText(\Dom\Element $node): void
    {
        foreach ($node->childNodes as $child) {
            if (($child instanceof \Dom\Text) === true) {
                $text = preg_replace('/\s+/', ' ', $child->textContent);
                $child->textContent = $text;
            } elseif (($child instanceof \Dom\Element) === true) {
                $this->normalizeNodeText($child);
            }
        }
    }

    private function processLists(\Dom\Element $element): void
    {
        $lists = $element->querySelectorAll('ul.list-disc, ol');
        foreach ($lists as $list) {
            if ($list instanceof \Dom\Element === false) {
                continue;
            }

            $list->removeAttribute('class');
            $list->setAttribute('style', self::CSS_UL);
            $items = $list->querySelectorAll('li');

            foreach ($items as $li) {
                if ($li instanceof \Dom\Element === false) {
                    continue;
                }

                $li->removeAttribute('class');
                $li->setAttribute('style', self::CSS_LI);
                $this->normalizeNodeText($li);
                $this->removeEmptyLinks($li);
            }
        }
    }

    private function removeEmptyLinks(\Dom\Element $element): void
    {
        $links = $element->querySelectorAll('a');
        foreach ($links as $link) {
            if ($link instanceof \Dom\Element === false) {
                continue;
            }

            if (trim($link->textContent) === '') {
                $link->remove();
            }
        }
    }

    private function processYouTubeIframes(string $html): string
    {
        return preg_replace_callback(
            '/<iframe[^>]*src=["\'](?:https?:)?\/\/(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]+)[^"\']*["\'][^>]*>/i',
            function ($matches) {
                $videoId = $matches[1];
                $thumbnailUrl = "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg";
                $fallbackUrl = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
                $thumbnailData = @file_get_contents($thumbnailUrl);

                if ($thumbnailData === false || strlen($thumbnailData) < 100) {
                    $thumbnailUrl = $fallbackUrl;
                }

                return $this->buildYouTubePreview($videoUrl, $thumbnailUrl);
            },
            $html
        );
    }

    private function processImages(string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
        libxml_clear_errors();
        $wrapper = $dom->querySelector('div');

        if ($wrapper === null) {
            return $html;
        }

        $this->limitImageSize($wrapper);
        $this->alignTextLeft($wrapper);

        return (string)$wrapper->innerHTML;
    }

    private function limitImageSize(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::CSS_IMG);
            }
        }
    }

    private function alignTextLeft(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('p, div, h1, h2, h3, h4, h5, h6') as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $style = (string)($element->getAttribute('style') ?? '');
            if ($style !== '') {
                $newStyle = preg_replace('/text-align\s*:\s*center\s*;?/i', '', $style);
                if (is_string($newStyle) === true && $newStyle !== $style) {
                    $newStyle = trim($newStyle);
                    if ($newStyle === '') {
                        $element->removeAttribute('style');
                    } else {
                        $element->setAttribute('style', $newStyle);
                    }
                }
            }

            $element->setAttribute('align', 'left');
        }
    }

    private function parseRelativeTime(string $relativeTime): int
    {
        if ($relativeTime === '') {
            return time();
        }

        $relativeTime = mb_strtolower($relativeTime);

        if (preg_match('/(\d+)\s*мин/', $relativeTime, $matches) === 1) {
            return time() - (int)$matches[1] * 60;
        }
        if (preg_match('/(\d+)\s*ч/', $relativeTime, $matches) === 1) {
            return time() - (int)$matches[1] * 3600;
        }
        if (preg_match('/(\d+)\s*д/', $relativeTime, $matches) === 1) {
            return time() - (int)$matches[1] * 86400;
        }
        if (preg_match('/(\d{2}):(\d{2})/', $relativeTime, $matches) === 1) {
            $today = date('Y-m-d');
            $timestamp = strtotime($today . ' ' . $matches[1] . ':' . $matches[2]);
            if ($timestamp === false) {
                return time();
            }
            return $timestamp;
        }

        return time();
    }
}
