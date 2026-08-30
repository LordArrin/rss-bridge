<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class AsahiShimbunAJWBridge extends BridgeAbstract
{
    public const NAME = 'Asahi Shimbun AJW';
    public const URI = 'http://www.asahi.com/ajw/';
    public const DESCRIPTION = 'Asahi Shimbun - Asia & Japan Watch';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'section' => [
                'type' => 'list',
                'name' => 'Section',
                'values' => [
                    'What\'s New' => 'new',
                    'National Report' => 'national_report',
                    'Politics' => 'politics',
                    'Business' => 'business',
                    'Asia & World' => 'asia_world',
                    'Asia & World >> China' => 'asia_world/china',
                    'Asia & World >> Korean Peninsula' => 'asia_world/korean_peninsula',
                    'Asia & World >> Around Asia' => 'asia_world/around_asia',
                    'Asia & World >> World' => 'asia_world/world',
                    'Sci & Tech' => 'sci_tech',
                    'Culture' => 'culture',
                    'Culture >> Style' => 'culture/style',
                    'Culture >> Food' => 'culture/food',
                    'Culture >> Movies' => 'culture/movies',
                    'Culture >> Manga & Anime' => 'culture/manga_anime',
                    'Culture >> People' => 'culture/people',
                    'Travel' => 'travel',
                    'Sports' => 'sports',
                    'Opinion' => 'opinion',
                    'Opinion >> Editorial' => 'opinion/editorial',
                    'Opinion >> Vox Populi' => 'opinion/vox',
                    'Opinion >> Views' => 'opinion/views',
                    'Special' => 'special',
                ],
                'defaultValue' => 'politics',
            ]
        ]
    ];

    public function collectData(): void
    {
        $section = (string)$this->getInput('section');
        $url = $this->getSectionURI($section);

        $html = getContents($url);
        if ($html === '') {
            throwServerException(sprintf('Failed to fetch %s', $url));
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $baseDomain = parse_url(self::URI, PHP_URL_SCHEME) . '://' . parse_url(self::URI, PHP_URL_HOST);

        $links = $dom->querySelectorAll('#MainInner li a');
        foreach ($links as $element) {
            $parent = $element->parentElement;
            if ($parent !== null) {
                $parentClass = $parent->getAttribute('class') ?? '';
                if ($parentClass === 'HeadlineTopImage-S') {
                    continue;
                }
            }

            $item = [];

            $href = $element->getAttribute('href') ?? '';
            $item['uri'] = $baseDomain . $href;

            $eLead = $element->querySelector('span.Lead');
            if ($eLead !== null) {
                $item['content'] = $eLead->innerHTML ?? '';
                $eLead->remove();
            } else {
                $item['content'] = $element->innerHTML ?? '';
            }

            $eDate = $element->querySelector('span.EnDate');
            if ($eDate !== null) {
                $dateText = trim($eDate->textContent ?? '');
                if ($dateText !== '') {
                    $parsed = strtotime($dateText);
                    if ($parsed !== false) {
                        $item['timestamp'] = $parsed;
                    }
                }
                $eDate->remove();
            }

            $eVideo = $element->querySelector('span.EnVideo');
            if ($eVideo !== null) {
                $eVideo->remove();
                $element->innerHTML = 'VIDEO: ' . ($element->innerHTML ?? '');
            }

            $eTitle = $element->querySelector('.title');
            if ($eTitle !== null) {
                $item['title'] = trim($eTitle->innerHTML ?? '');
            } else {
                $item['title'] = trim($element->innerHTML ?? '');
            }

            if ($item['title'] === '' || $href === '') {
                continue;
            }

            $this->items[] = $item;
        }
    }

    private function getSectionURI(string $section): string
    {
        return self::URI . $section . '/';
    }
}
