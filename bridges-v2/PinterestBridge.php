<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class PinterestBridge extends FeedExpander
{
    public const NAME = 'Pinterest';
    public const URI = 'https://www.pinterest.com';
    public const DESCRIPTION = 'Returns the newest images on a board';
    public const MAINTAINER = 'No maintainer';

    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'By username and board' => [
            'u' => [
                'name' => 'username',
                'exampleValue' => 'VIGOIndustries',
                'required' => true
            ],
            'b' => [
                'name' => 'board',
                'exampleValue' => 'bathroom-remodels',
                'required' => true
            ]
        ]
    ];

    public function getIcon(): string
    {
        return 'https://s.pinimg.com/webapp/style/images/favicon-9f8f9adf.png';
    }

    public function collectData(): void
    {
        $this->collectExpandableDatas($this->getURI() . '.rss');
        $this->fixLowRes();
    }

    private function fixLowRes(): void
    {
        $newitems = [];
        $pattern = '/https\:\/\/i\.pinimg\.com\/[a-zA-Z0-9]*x\//';

        foreach ($this->items as $item) {
            $title = $item['title'] ?? '';
            if (is_string($title) === true) {
                $item['title'] = $this->cleanTitle($title);
            }

            $content = $item['content'] ?? '';
            if (is_string($content) === true && $content !== '') {
                $replaced = preg_replace($pattern, 'https://i.pinimg.com/originals/', $content);
                if ($replaced !== null) {
                    $content = $replaced;
                }
                $item['content'] = $this->normalizeImages($this->cleanWhitespace($content));
            }

            unset($item['enclosures']);

            $newitems[] = $item;
        }

        $this->items = $newitems;
    }

    private function cleanTitle(string $title): string
    {
        $title = $this->cleanWhitespace($title);
        if ($title === '') {
            return '';
        }

        $dotPos = mb_strpos($title, '.');
        if ($dotPos !== false && $dotPos > 0) {
            return trim(mb_substr($title, 0, $dotPos));
        }

        $words = preg_split('/\s+/', $title, 4, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return $title;
        }

        if (count($words) <= 3) {
            return $title;
        }

        return implode(' ', array_slice($words, 0, 3));
    }

    private function cleanWhitespace(string $text): string
    {
        $text = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function normalizeImages(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        try {
            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString('<div id="pin-wrap">' . $content . '</div>');
            libxml_use_internal_errors(false);
        } catch (\Throwable) {
            libxml_use_internal_errors(false);
            return $content;
        }

        $wrapper = $dom->querySelector('#pin-wrap');
        if ($wrapper === null) {
            return $content;
        }

        $imgStyle = 'display: block !important; max-width: 100% !important; width: auto !important; height: auto !important; float: none !important; clear: both !important; margin: 0 0 12px 0 !important;';

        foreach ($wrapper->querySelectorAll('img') as $img) {
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->removeAttribute('align');
            $img->setAttribute('style', $imgStyle);

            $parent = $img->parentElement;
            if ($parent instanceof \Dom\Element === true && $parent->localName === 'a') {
                $parent->setAttribute('style', 'display: block; clear: both;');
            }
        }

        return $wrapper->innerHTML ?? $content;
    }

    public function getURI(): string
    {
        if ($this->queriedContext === 'By username and board') {
            return self::URI . '/' . urlencode((string)$this->getInput('u')) . '/' . urlencode((string)$this->getInput('b'));
        }

        return parent::getURI();
    }

    public function getName(): string
    {
        if ($this->queriedContext === 'By username and board') {
            return $this->getInput('u') . ' - ' . $this->getInput('b') . ' - ' . self::NAME;
        }

        return parent::getName();
    }
}
