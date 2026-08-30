<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class LostArmourBridge extends BridgeAbstract
{
    public const MAINTAINER = 'LordArrin';
    public const NAME = 'LostArmour';
    public const URI = 'https://lostarmour.info';
    public const DESCRIPTION = 'Daily reports from the war in Ukraine from lostarmour.info';
    public const CACHE_TIMEOUT = 21600;

    private const MIN_IMAGES = 3;

    private const ELEMENTS_TO_REMOVE = [
        'header',
        'footer',
        'nav',
        'h6',
        'script',
        'style',
        'hr',
        '#laLeft',
        '#laRight',
        '#map',
        '#carouselExampleCaptions',
        '.comments',
        '.sidebar',
        '.menu',
        '.nav',
        '.header',
        '.footer',
        '.pt-3',
        '.media-video-card',
        '.carousel',
        '.map',
        '[class*="carousel"]',
        '[id*="map"]',
    ];

    private const CSS = [
        'table' => 'width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px;',
        'cell' => 'border: 2px solid #ccc; padding: 6px 10px; text-align: left; vertical-align: top;',
        'image' => 'display: block; max-width: 1500px; height: auto; margin: 20px 0;',
    ];

    public function collectData()
    {
        $today = new \DateTime();

        for ($i = 0; $i < 3; $i++) {
            $date = clone $today;
            $date->sub(new \DateInterval('P' . $i . 'D'));
            $this->collectDayData($date);
        }
    }

    private function collectDayData(\DateTime $date): void
    {
        $dateStr = $date->format('d-m-Y');
        $url = self::URI . '/summary/voyna_na_ukraine-svodka-za-' . $dateStr;

        try {
            $html = getContents($url);
        } catch (\Exception $e) {
            return;
        }

        if ($html === '') {
            return;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $h1 = $dom->querySelector('h1');
        if ($h1 === null) {
            return;
        }

        $title = trim($h1->textContent ?? '');
        if ($title === '') {
            return;
        }

        $author = null;
        if (preg_match('/Автор статьи:\s*([^\n<]+)/', $html, $matches) === 1) {
            $author = trim($matches[1]);
        }

        $this->removeUnwantedElements($dom);
        $this->removeAuthorBlock($dom);
        $this->removeMapHeader($dom);
        $this->removeHtmlComments($dom);
        $this->fixTables($dom);
        $this->makeUrlsAbsolute($dom);
        $this->fixImages($dom);
        $this->removeEmptyWrappers($dom);

        $article = $dom->querySelector('article') ?? $dom->querySelector('main') ?? $dom->querySelector('.content') ?? $dom->querySelector('body');

        if ($article === null) {
            return;
        }

        $h1 = $dom->querySelector('h1');
        if ($h1 !== null) {
            $h1->remove();
        }

        $content = $article->innerHTML ?? '';
        $content = $this->cleanMsOfficeMarkup($content);
        $content = $this->cleanNbsp($content);

        if ($content === '' || substr_count($content, '<img ') < self::MIN_IMAGES) {
            return;
        }

        $item = [
            'title' => $title,
            'uri' => $url,
            'content' => $content,
            'timestamp' => $date->getTimestamp(),
            'author' => $author,
        ];

        $this->items[] = $item;
    }

    private function removeUnwantedElements(\Dom\HTMLDocument $dom): void
    {
        foreach (self::ELEMENTS_TO_REMOVE as $selector) {
            $elements = iterator_to_array($dom->querySelectorAll($selector));
            foreach ($elements as $el) {
                if ($el->parentNode !== null) {
                    $el->parentNode->removeChild($el);
                }
            }
        }
    }

    private function removeAuthorBlock(\Dom\HTMLDocument $dom): void
    {
        $alerts = iterator_to_array($dom->querySelectorAll('.alert'));
        foreach ($alerts as $el) {
            $text = $el->textContent ?? '';
            if (str_contains($text, 'Автор статьи') === true) {
                if ($el->parentNode !== null) {
                    $el->parentNode->removeChild($el);
                }
            }
        }
    }

    private function removeMapHeader(\Dom\HTMLDocument $dom): void
    {
        $headers = iterator_to_array($dom->querySelectorAll('h2'));
        foreach ($headers as $el) {
            $text = $el->textContent ?? '';
            if (str_contains($text, 'Карта БД') === true) {
                if ($el->parentNode !== null) {
                    $el->parentNode->removeChild($el);
                }
            }
        }
    }

    private function removeHtmlComments(\Dom\HTMLDocument $dom): void
    {
        $this->removeCommentsRecursive($dom);
    }

    private function removeCommentsRecursive(\Dom\Node $node): void
    {
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                if ($node instanceof \Dom\Element || $node instanceof \Dom\HTMLDocument) {
                    $node->removeChild($child);
                }
            } elseif ($child->hasChildNodes() === true) {
                $this->removeCommentsRecursive($child);
            }
        }
    }

    private function fixTables(\Dom\HTMLDocument $dom): void
    {
        $tableWrappers = iterator_to_array($dom->querySelectorAll('.table-responsive'));
        foreach ($tableWrappers as $wrapper) {
            $table = $wrapper->querySelector('table');
            if ($table === null) {
                continue;
            }

            $table->setAttribute('style', self::CSS['table']);

            foreach ($table->querySelectorAll('th, td') as $cell) {
                $cell->setAttribute('style', self::CSS['cell']);
            }

            if ($wrapper->parentNode !== null) {
                $wrapper->parentNode->replaceChild($table, $wrapper);
            }
        }
    }

    private function makeUrlsAbsolute(\Dom\HTMLDocument $dom): void
    {
        $baseUrl = self::URI;

        $selectors = [
            'img[src]',
            'a[href]',
            'source[src]',
            'video[src]',
            'video[poster]',
            'audio[src]',
            'iframe[src]',
        ];

        foreach ($selectors as $selector) {
            $elements = iterator_to_array($dom->querySelectorAll($selector));
            foreach ($elements as $el) {
                if ($el instanceof \Dom\Element) {
                    if ($selector === 'video[poster]') {
                        $attr = 'poster';
                    } else {
                        $attr = match (true) {
                            str_contains($selector, '[src]') => 'src',
                            str_contains($selector, '[href]') => 'href',
                            default => 'src',
                        };
                    }

                    $url = $el->getAttribute($attr);
                    if ($url !== null && $url !== '') {
                        $absoluteUrl = urljoin($baseUrl, $url);
                        $el->setAttribute($attr, $absoluteUrl);
                    }
                }
            }
        }
    }

    private function fixImages(\Dom\HTMLDocument $dom): void
    {
        $images = iterator_to_array($dom->querySelectorAll('img'));
        foreach ($images as $img) {
            if ($img instanceof \Dom\Element) {
                $img->setAttribute('style', self::CSS['image']);
            }
        }
    }

    private function removeEmptyWrappers(\Dom\HTMLDocument $dom): void
    {
        $wrapperTags = ['div', 'section', 'aside', 'span'];

        for ($pass = 0; $pass < 3; $pass++) {
            $removed = false;
            foreach ($wrapperTags as $tag) {
                $elements = iterator_to_array($dom->querySelectorAll($tag));
                foreach ($elements as $el) {
                    $innerHTML = trim($el->innerHTML ?? '');
                    if ($innerHTML === '' || $innerHTML === '&nbsp;') {
                        if ($el->parentNode !== null) {
                            $el->parentNode->removeChild($el);
                            $removed = true;
                        }
                    }
                }
            }
            if ($removed === false) {
                break;
            }
        }
    }

    private function cleanMsOfficeMarkup(string $html): string
    {
        $html = preg_replace('/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/s', '', $html);
        $html = preg_replace('/<!--\s*\[if[^\]]*\]-->/s', '', $html);
        $html = preg_replace('/<!--\[endif\]-->/s', '', $html);
        return $html;
    }

    private function cleanNbsp(string $html): string
    {
        $html = preg_replace('/(&nbsp;|\xC2\xA0)+/', ' ', $html);
        $html = preg_replace('/<p>\s*<\/p>/', '', $html);
        return $html;
    }
}
