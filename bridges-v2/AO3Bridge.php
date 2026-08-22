<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class AO3Bridge extends BridgeAbstract
{
    public const NAME = 'AO3';
    public const URI = 'https://archiveofourown.org/';
    public const CACHE_TIMEOUT = 1800;
    public const DESCRIPTION = 'Returns works or chapters from Archive of Our Own';
    public const MAINTAINER = 'No maintainer';

    public const PARAMETERS = [
        'List' => [
            'url' => [
                'name' => 'url',
                'required' => true,
                'exampleValue' => 'https://archiveofourown.org/tags/F*s*F/works',
            ],
            'range' => [
                'name' => 'Chapter Content',
                'title' => 'Chapter(s) to include in each work\'s feed entry',
                'defaultValue' => null,
                'type' => 'list',
                'values' => [
                    'None' => null,
                    'First' => 'first',
                    'Latest' => 'last',
                    'Entire work' => 'all',
                ],
            ],
            'unique' => [
                'name' => 'Make separate entries for new fic chapters',
                'type' => 'checkbox',
                'required' => false,
                'title' => 'Make separate entries for new fic chapters',
                'defaultValue' => 'checked',
            ],
            'limit' => self::LIMIT,
        ],
        'Bookmarks' => [
            'user' => [
                'name' => 'user',
                'required' => true,
                'exampleValue' => 'Nyaaru',
            ],
        ],
        'Work' => [
            'id' => [
                'name' => 'id',
                'required' => true,
                'exampleValue' => '18181853',
            ],
        ]
    ];

    private ?string $title = null;

    public function collectData(): void
    {
        switch ($this->queriedContext) {
            case 'Bookmarks':
                $this->collectList($this->getURI());
                break;
            case 'List':
                $this->collectList($this->getURI());
                break;
            case 'Work':
                $this->collectWork($this->getURI());
                break;
        }
    }

    private function collectList(string $url): void
    {
        $version = 'v0.0.1';
        $headers = [
            'useragent: rss-bridge ' . $version . ' (https://github.com/RSS-Bridge/rss-bridge)'
        ];
        $response = getContents($url, $headers);
        if ($response === '') {
            throwServerException(sprintf('Failed to fetch %s', $url));
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($response);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($dom->documentElement);

        $heading = $dom->querySelector('#main h2');
        if ($heading === null) {
            throwServerException('Unable to find page heading');
        }

        $tagLink = $heading->querySelector('a.tag');
        if ($tagLink !== null) {
            $heading = $tagLink;
        }
        $this->title = trim($heading->textContent ?? '');

        $limit = (int)($this->getInput('limit') ?? 3);
        $count = 0;

        $elements = $dom->querySelectorAll('.index.group > li');
        foreach ($elements as $element) {
            $titleLink = $element->querySelector('div h4 a');
            if ($titleLink === null) {
                continue;
            }

            $item = [];
            $item['title'] = trim($titleLink->textContent ?? '');
            $item['uri'] = $titleLink->getAttribute('href') ?? '';

            $datetimeEl = $element->querySelector('div p.datetime');
            $strdate = $datetimeEl !== null ? trim($datetimeEl->textContent ?? '') : '';
            $parsed = $strdate !== '' ? strtotime($strdate) : false;
            $item['timestamp'] = $parsed !== false ? $parsed : null;

            $elementDom = $this->reparseElement($element);
            if ($elementDom === null) {
                continue;
            }

            $categories = [];

            $requiredTags = $elementDom->documentElement->querySelector('ul.required-tags');
            if ($requiredTags !== null) {
                foreach ($requiredTags->childNodes as $tag) {
                    if ($tag instanceof \Dom\Element) {
                        $categories[] = html_entity_decode($tag->textContent ?? '', ENT_QUOTES, 'UTF-8');
                    }
                }
                $requiredTags->remove();
            }

            $tags = $elementDom->documentElement->querySelector('ul.tags');
            if ($tags !== null) {
                foreach ($tags->childNodes as $tag) {
                    if ($tag instanceof \Dom\Element) {
                        $categories[] = html_entity_decode($tag->textContent ?? '', ENT_QUOTES, 'UTF-8');
                    }
                }
                $tags->remove();
            }

            if ($categories !== []) {
                $item['categories'] = $categories;
            }

            $childrenHtml = '';
            foreach ($elementDom->documentElement->childNodes as $child) {
                $childrenHtml .= $elementDom->saveHTML($child);
            }
            $item['content'] = $childrenHtml;

            $chaptersEl = $elementDom->documentElement->querySelector('dl dd.chapters');
            $chapters = $chaptersEl !== null ? trim($chaptersEl->textContent ?? '') : '0';

            if ($this->getInput('unique') === true) {
                $item['uid'] = $item['uri'] . '/' . $strdate . '/' . $chapters;
            } else {
                $item['uid'] = $item['uri'];
            }

            $range = $this->getInput('range');
            if ($range !== null && ($limit === 0 || $count++ < $limit)) {
                $workUrl = $item['uri'];
                switch ($range) {
                    case 'all':
                        $workUrl .= '?view_full_work=true';
                        break;
                    case 'first':
                        break;
                    case 'last':
                        $workUrl .= '/navigate';
                        $navResponse = getContents($workUrl, $headers);
                        if ($navResponse !== '') {
                            libxml_use_internal_errors(true);
                            $navDom = \Dom\HTMLDocument::createFromString($navResponse);
                            libxml_use_internal_errors(false);
                            $this->resolveRelativeUrls($navDom->documentElement);
                            $links = $navDom->querySelectorAll('ol.index.group > li > a');
                            $linksArray = iterator_to_array($links);
                            if ($linksArray !== []) {
                                $lastLink = end($linksArray);
                                $href = $lastLink->getAttribute('href');
                                if ($href !== null) {
                                    $workUrl = $href;
                                }
                            }
                        }
                        break;
                }

                $workResponse = getContents($workUrl, $headers);
                if ($workResponse !== '') {
                    libxml_use_internal_errors(true);
                    $workDom = \Dom\HTMLDocument::createFromString($workResponse);
                    libxml_use_internal_errors(false);
                    $this->resolveRelativeUrls($workDom->documentElement);

                    $ficsum = $workDom->querySelector('#workskin > .preface > .summary');
                    if ($ficsum !== null) {
                        $ficsum->remove();
                    }

                    $workskin = $workDom->querySelector('#workskin');
                    if ($workskin !== null) {
                        $item['content'] .= $workDom->saveHTML($workskin);
                    }
                }
            }

            $wid = $this->extractWorkId($item['uri']);
            if ($wid !== '') {
                $enclosures = [];
                foreach (['azw3', 'epub', 'mobi', 'pdf', 'html'] as $ext) {
                    $enclosures[] = 'https://archiveofourown.org/downloads/' . $wid . '/work.' . $ext;
                }
                $item['enclosures'] = $enclosures;
            }

            $this->items[] = $item;
        }
    }

    private function collectWork(string $url): void
    {
        $version = 'v0.0.1';
        $headers = [
            'useragent: rss-bridge ' . $version . ' (https://github.com/RSS-Bridge/rss-bridge)'
        ];

        $navResponse = getContents($url . '/navigate', $headers);
        if ($navResponse === '') {
            throwServerException(sprintf('Failed to fetch %s', $url . '/navigate'));
        }

        libxml_use_internal_errors(true);
        $navDom = \Dom\HTMLDocument::createFromString($navResponse);
        libxml_use_internal_errors(false);
        $this->resolveRelativeUrls($navDom->documentElement);

        $workResponse = getContents($url . '?view_full_work=true', $headers);
        if ($workResponse === '') {
            throwServerException(sprintf('Failed to fetch %s', $url . '?view_full_work=true'));
        }

        libxml_use_internal_errors(true);
        $workDom = \Dom\HTMLDocument::createFromString($workResponse);
        libxml_use_internal_errors(false);
        $this->resolveRelativeUrls($workDom->documentElement);

        $titleLink = $navDom->querySelector('h2 a');
        if ($titleLink !== null) {
            $this->title = trim($titleLink->textContent ?? '');
        }

        $navItems = iterator_to_array($navDom->querySelectorAll('ol.index.group > li'));
        $total = count($navItems);

        for ($i = 0; $i < $total; $i++) {
            $element = $navItems[$i];
            $link = $element->querySelector('a');
            if ($link === null) {
                continue;
            }

            $item = [];
            $item['title'] = trim($link->textContent ?? '');
            $item['uri'] = $link->getAttribute('href') ?? '';

            $chapterEl = $workDom->querySelector('#chapter-' . ($i + 1));
            $item['content'] = $chapterEl !== null ? $workDom->saveHTML($chapterEl) : '';

            $datetimeEl = $element->querySelector('span.datetime');
            $strdate = $datetimeEl !== null ? trim($datetimeEl->textContent ?? '') : '';
            $strdate = str_replace(['(', ')'], '', $strdate);
            $parsed = $strdate !== '' ? strtotime($strdate) : false;
            $item['timestamp'] = $parsed !== false ? $parsed : null;

            $item['uid'] = $item['uri'] . '/' . $strdate;

            $this->items[] = $item;
        }

        $this->items = array_reverse($this->items);
    }

    private function extractWorkId(string $uri): string
    {
        if (preg_match('#/works/(\d+)#', $uri, $matches) === 1) {
            return $matches[1];
        }
        return '';
    }

    private function reparseElement(\Dom\Element $element): ?\Dom\HTMLDocument
    {
        $ownerDoc = $element->ownerDocument;
        if ($ownerDoc === null) {
            return null;
        }
        $html = $ownerDoc->saveHTML($element);
        if ($html === false || $html === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $newDom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $newDom;
    }

    private function resolveRelativeUrls(?\Dom\Element $container): void
    {
        if ($container === null) {
            return;
        }

        $base = rtrim(self::URI, '/');
        $elements = $container->querySelectorAll('[src], [href]');
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

    public function getName(): string
    {
        $name = parent::getName() . ' ' . (string)$this->queriedContext;
        if ($this->title !== null && $this->title !== '') {
            $name .= ' - ' . $this->title;
        }
        return $name;
    }

    public function getIcon(): string
    {
        return self::URI . 'favicon.ico';
    }

    public function getURI(): string
    {
        $url = parent::getURI();
        switch ($this->queriedContext) {
            case 'Bookmarks':
                $user = (string)($this->getInput('user') ?? '');
                $url = self::URI
                    . 'users/' . $user
                    . '/bookmarks?bookmark_search[sort_column]=bookmarkable_date';
                break;
            case 'List':
                $inputUrl = $this->getInput('url');
                if ($inputUrl !== null && $inputUrl !== '') {
                    $url = (string)$inputUrl;
                }
                break;
            case 'Work':
                $id = (string)($this->getInput('id') ?? '');
                $url = self::URI . 'works/' . $id;
                break;
        }
        return $url;
    }
}
