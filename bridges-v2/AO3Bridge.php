<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function RSSBridge\Exceptions\throwServerException;

final class AO3Bridge extends BridgeAbstract
{
    public const NAME = 'Archive of Our Own';
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
            'limit' => [
                'name' => 'Item limit',
                'type' => 'number',
                'defaultValue' => 3,
            ],
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

    // AO3 asked us to identify as a bot rather than imitate a browser (RSS-Bridge#3188)
    private const HEADERS = [
        'User-Agent: rss-bridge bot (https://github.com/LordArrin/rss-bridge)',
        // Empty values make curl drop curl-impersonate's browser-only headers
        'Sec-Ch-Ua:',
        'Sec-Ch-Ua-Mobile:',
        'Sec-Ch-Ua-Platform:',
        'Sec-Fetch-Dest:',
        'Sec-Fetch-Mode:',
        'Sec-Fetch-Site:',
        'Sec-Fetch-User:',
        'Upgrade-Insecure-Requests:',
        'Priority:',
    ];

    private ?string $title = null;

    public function collectData(): void
    {
        $queriedContext = $this->queriedContext;
        if ($queriedContext === 'Bookmarks' || $queriedContext === 'List') {
            $this->collectList($this->getURI());
        } elseif ($queriedContext === 'Work') {
            $this->collectWork($this->getURI());
        }
    }

    private function getContentsWithFallback(string $url, array $headers): string
    {
        $mirrors = [
            'https://insecure.archiveofourown.org/',
            'https://staging.archiveofourown.org/',
            'https://archive.transformativeworks.org',
            'https://archiveofourown.gay',
            'https://secure.ao3.org/',
            'https://ao3-mirror.cc/',
            'https://dry-water-e911.ao365.workers.dev/',
            'https://mute-disk-cf7e.ao33.workers.dev/',
        ];

        $urlsToTry = [$url];
        foreach ($mirrors as $mirror) {
            $replaced = preg_replace(
                '#^https?://archiveofourown\.org#i',
                rtrim($mirror, '/'),
                $url
            );
            if (is_string($replaced) === true) {
                $urlsToTry[] = $replaced;
            }
        }

        $lastException = null;
        foreach ($urlsToTry as $tryUrl) {
            try {
                $response = getContents($tryUrl, $headers);
                if ($response !== '') {
                    return $response;
                }
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        if ($lastException !== null) {
            throwServerException('Failed to fetch URL after trying all mirrors: ' . $lastException->getMessage());
        }

        throwServerException('Failed to fetch URL');
    }

    private function collectList(string $url): void
    {
        $headers = self::HEADERS;

        $response = $this->getContentsWithFallback($url, $headers);
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

        $limitInput = $this->getInput('limit');
        $limit = is_numeric($limitInput) === true ? (int) $limitInput : 3;
        $count = 0;

        $elements = $dom->querySelectorAll('.index.group > li');
        foreach ($elements as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

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
            if ($elementDom === null || $elementDom->documentElement === null) {
                continue;
            }

            $docElement = $elementDom->documentElement;
            $categories = [];

            $requiredTags = $docElement->querySelector('ul.required-tags');
            if ($requiredTags !== null) {
                foreach ($requiredTags->childNodes as $tag) {
                    if ($tag instanceof \Dom\Element) {
                        $categories[] = html_entity_decode($tag->textContent ?? '', ENT_QUOTES, 'UTF-8');
                    }
                }
                $requiredTags->remove();
            }

            $tags = $docElement->querySelector('ul.tags');
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
            foreach ($docElement->childNodes as $child) {
                $childrenHtml .= $elementDom->saveHtml($child);
            }
            $item['content'] = $childrenHtml;

            $chaptersEl = $docElement->querySelector('dl dd.chapters');
            $chapters = $chaptersEl !== null ? trim($chaptersEl->textContent ?? '') : '0';

            $uniqueInput = $this->getInput('unique');
            if (is_bool($uniqueInput) === true && $uniqueInput === true) {
                $item['uid'] = $item['uri'] . '/' . $strdate . '/' . $chapters;
            } else {
                $item['uid'] = $item['uri'];
            }

            $range = $this->getInput('range');
            if ($range !== null && ($limit === 0 || $count < $limit)) {
                $workUrl = $item['uri'];
                if (is_string($range) === true) {
                    if ($range === 'all') {
                        $workUrl .= '?view_full_work=true';
                    } elseif ($range === 'last') {
                        $workUrl .= '/navigate';
                        $navResponse = $this->getContentsWithFallback($workUrl, $headers);
                        if ($navResponse !== '') {
                            libxml_use_internal_errors(true);
                            $navDom = \Dom\HTMLDocument::createFromString($navResponse);
                            libxml_use_internal_errors(false);
                            $this->resolveRelativeUrls($navDom->documentElement);
                            $links = $navDom->querySelectorAll('ol.index.group > li > a');
                            $linksArray = iterator_to_array($links);
                            if ($linksArray !== []) {
                                $lastLink = end($linksArray);
                                if ($lastLink instanceof \Dom\Element) {
                                    $href = $lastLink->getAttribute('href');
                                    if ($href !== null) {
                                        $workUrl = $href;
                                    }
                                }
                            }
                        }
                    }
                }

                $workResponse = $this->getContentsWithFallback($workUrl, $headers);
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
                        $item['content'] .= $workDom->saveHtml($workskin);
                    }
                }
                $count++;
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
        $headers = self::HEADERS;

        $navResponse = $this->getContentsWithFallback($url . '/navigate', $headers);
        if ($navResponse === '') {
            throwServerException(sprintf('Failed to fetch %s', $url . '/navigate'));
        }

        libxml_use_internal_errors(true);
        $navDom = \Dom\HTMLDocument::createFromString($navResponse);
        libxml_use_internal_errors(false);
        $this->resolveRelativeUrls($navDom->documentElement);

        $workResponse = $this->getContentsWithFallback($url . '?view_full_work=true', $headers);
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
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $link = $element->querySelector('a');
            if ($link === null) {
                continue;
            }

            $item = [];
            $item['title'] = trim($link->textContent ?? '');
            $item['uri'] = $link->getAttribute('href') ?? '';

            $chapterEl = $workDom->querySelector('#chapter-' . ($i + 1));
            $item['content'] = $chapterEl !== null ? $workDom->saveHtml($chapterEl) : '';

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
        if ($ownerDoc instanceof \Dom\HTMLDocument === false) {
            return null;
        }

        $html = $ownerDoc->saveHtml($element);
        if ($html === '') {
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
            if ($el instanceof \Dom\Element === false) {
                continue;
            }

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
        $name = parent::getName() . ' ' . (string) $this->queriedContext;
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
        $queriedContext = $this->queriedContext;

        if ($queriedContext === 'Bookmarks') {
            $user = (string) ($this->getInput('user') ?? '');
            $url = self::URI . 'users/' . $user . '/bookmarks?bookmark_search[sort_column]=bookmarkable_date';
        } elseif ($queriedContext === 'List') {
            $inputUrl = $this->getInput('url');
            if (is_string($inputUrl) === true && $inputUrl !== '') {
                $url = $inputUrl;
            }
        } elseif ($queriedContext === 'Work') {
            $id = (string) ($this->getInput('id') ?? '');
            $url = self::URI . 'works/' . $id;
        }

        return $url;
    }
}
