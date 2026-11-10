<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class AnisearchBridge extends BridgeAbstract
{
    public const NAME = 'Anisearch';
    public const URI = 'https://www.anisearch.de';
    public const DESCRIPTION = 'Feed for Anisearch';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        [
            'language' => [
                'name' => 'Site Language',
                'type' => 'list',
                'values' => [
                    'Japanese' => 'jp',
                    'English' => 'com',
                    'German' => 'de',
                    'Spanish' => 'es',
                    'French' => 'fr',
                    'Italian' => 'it',
                ],
                'defaultValue' => 'com',
            ],
            'dubbed' => [
                'name' => 'Dubbed Language',
                'type' => 'list',
                'values' => [
                    'Japanese' => 'jp',
                    'English' => 'com',
                    'German' => 'de',
                    'Spanish' => 'es',
                    'French' => 'fr',
                    'Italian' => 'it',
                ],
                'defaultValue' => 'com',
            ],
            'trailers' => [
                'name' => 'Include Trailers',
                'type' => 'checkbox',
                'title' => 'Will include trailers',
                'defaultValue' => false,
            ],
        ]
    ];

    public function getURI(): string
    {
        $siteLang = (string) $this->getInput('language');
        if ($siteLang === '') {
            $siteLang = 'de';
        }
        return 'https://www.anisearch.' . $siteLang;
    }

    public function collectData(): void
    {
        $siteLang = (string) $this->getInput('language');
        if ($siteLang === '') {
            $siteLang = 'de';
        }

        $dubLang = (string) $this->getInput('dubbed');
        if ($dubLang === '') {
            $dubLang = 'de';
        }

        $includeTrailers = (bool) $this->getInput('trailers');
        $limit = $includeTrailers === true ? 5 : 10;

        $baseUrl = 'https://www.anisearch.' . $siteLang;
        $categoryUrl = $baseUrl . '/anime/index/page-1?char=all&dubbed=' . $dubLang . '&sort=newest&order=desc&view=4';

        $html = (string) getContents($categoryUrl);
        $dom = \Dom\HTMLDocument::createFromString($html);

        $listItems = $dom->querySelectorAll('li.btype0');
        $count = 0;

        foreach ($listItems as $li) {
            if ($li instanceof \Dom\Element === false) {
                continue;
            }
            if ($count >= $limit) {
                break;
            }

            $a = $li->querySelector('a');
            if ($a instanceof \Dom\Element === false) {
                continue;
            }

            $titleSpan = $a->querySelector('span.title');
            $title = $titleSpan instanceof \Dom\Element ? $titleSpan->textContent : 'Unknown Title';

            $href = $a->getAttribute('href');
            if (is_string($href) === false || $href === '') {
                continue;
            }
            $url = urljoin($baseUrl, $href);

            $articleHtml = (string) getContents($url);
            $articleDom = \Dom\HTMLDocument::createFromString($articleHtml);

            $contentDiv = $articleDom->querySelector('div.details-text');
            $headerImage = $articleDom->querySelector('img#details-cover');

            if ($contentDiv instanceof \Dom\Element === false) {
                continue;
            }

            $hiddenElements = $contentDiv->querySelectorAll('.hidden');
            foreach ($hiddenElements as $hidden) {
                if ($hidden instanceof \Dom\Element === true) {
                    $hidden->remove();
                }
            }

            $contentHtml = $contentDiv->innerHTML ?? '';

            $imageHtml = '';
            if ($headerImage instanceof \Dom\Element === true) {
                $src = $headerImage->getAttribute('src');
                if (is_string($src) === true && $src !== '') {
                    $absoluteSrc = urljoin($baseUrl, $src);
                    $imageHtml = sprintf(
                        '<img src="%s" style="display: block; max-width: 1200px; width: 100%%; height: auto; margin: 1em 0;">',
                        e($absoluteSrc)
                    );
                }
            }

            $ytlink = '';
            if ($includeTrailers === true) {
                $trailerSelector = 'section#trailers > div > div.swiper > ul.swiper-wrapper > li.swiper-slide > a';
                $trailerLink = $articleDom->querySelector($trailerSelector);

                if ($trailerLink instanceof \Dom\Element === true) {
                    $trailerHref = $trailerLink->getAttribute('href');

                    if (is_string($trailerHref) === true && $trailerHref !== '') {
                        $trailerUrl = urljoin($baseUrl, $trailerHref);
                        $trailerHtml = (string) getContents($trailerUrl);
                        $trailerDom = \Dom\HTMLDocument::createFromString($trailerHtml);

                        $iframe = $trailerDom->querySelector('div#video > iframe');

                        if ($iframe instanceof \Dom\Element === true) {
                            $xsrc = $iframe->getAttribute('data-xsrc');

                            if (is_string($xsrc) === true && $xsrc !== '') {
                                $attrs = 'width="560" height="315" frameborder="0" ';
                                $attrs .= 'allow="accelerometer; autoplay; clipboard-write; ';
                                $attrs .= 'encrypted-media; gyroscope; picture-in-picture; web-share" ';
                                $attrs .= 'referrerpolicy="strict-origin" allowfullscreen';

                                $ytlink = sprintf(
                                    '<br><iframe src="%s" title="YouTube video player" %s></iframe>',
                                    e($xsrc),
                                    $attrs
                                );
                            }
                        }
                    }
                }
            }

            $this->items[] = [
                'title' => trim($title),
                'uri' => $url,
                'content' => $imageHtml . '<br>' . $contentHtml . $ytlink,
            ];

            $count++;
        }
    }
}
