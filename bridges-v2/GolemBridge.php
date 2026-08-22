<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class GolemBridge extends FeedExpander
{
    public const NAME = 'Golem';
    public const URI = 'https://www.golem.de/';
    public const DESCRIPTION = 'Returns the full articles instead of only the intro';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [[
        'category' => [
            'name' => 'Category',
            'type' => 'list',
            'values' => [
                'Alle News'
                => 'https://rss.golem.de/rss.php?feed=ATOM1.0',
                'Audio/Video'
                => 'https://rss.golem.de/rss.php?ms=audio-video&feed=ATOM1.0',
                'Auto'
                => 'https://rss.golem.de/rss.php?ms=auto&feed=ATOM1.0',
                'Foto'
                => 'https://rss.golem.de/rss.php?ms=foto&feed=ATOM1.0',
                'Games'
                => 'https://rss.golem.de/rss.php?ms=games&feed=ATOM1.0',
                'Handy'
                => 'https://rss.golem.de/rss.php?ms=handy&feed=ATOM1.0',
                'Internet'
                => 'https://rss.golem.de/rss.php?ms=internet&feed=ATOM1.0',
                'Mobil'
                => 'https://rss.golem.de/rss.php?ms=mobil&feed=ATOM1.0',
                'Open Source'
                => 'https://rss.golem.de/rss.php?ms=open-source&feed=ATOM1.0',
                'Politik/Recht'
                => 'https://rss.golem.de/rss.php?ms=politik-recht&feed=ATOM1.0',
                'Security'
                => 'https://rss.golem.de/rss.php?ms=security&feed=ATOM1.0',
                'Desktop-Applikationen'
                => 'https://rss.golem.de/rss.php?ms=desktop-applikationen&feed=ATOM1.0',
                'Software-Entwicklung'
                => 'https://rss.golem.de/rss.php?ms=softwareentwicklung&feed=ATOM1.0',
                'Wirtschaft'
                => 'https://rss.golem.de/rss.php?ms=wirtschaft&feed=ATOM1.0',
                'Wissenschaft'
                => 'https://rss.golem.de/rss.php?ms=wissenschaft&feed=ATOM1.0'
            ]
        ],
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'required' => false,
            'title' => 'Specify number of full articles to return',
            'defaultValue' => 5
        ]
    ]];
    public const LIMIT = 5;
    public const HEADERS = ['Cookie: golem_consent20=simple|250101;'];

    public function collectData(): void
    {
        $limit = (int)$this->getInput('limit');
        if ($limit === 0) {
            $limit = static::LIMIT;
        }
        $this->collectExpandableDatas(
            $this->getInput('category'),
            $limit
        );
    }

    protected function parseItem($item)
    {
        $item['content'] ??= '';
        $uri = $item['uri'];

        $urls = [];

        while ($uri !== null) {
            if (isset($urls[$uri]) === true) {
                break;
            }
            $urls[$uri] = true;

            $articlePage = getSimpleHTMLDOMCached($uri, static::CACHE_TIMEOUT, static::HEADERS);
            $articlePage = defaultLinkTo($articlePage, $uri);

            $item['uri'] = $articlePage->find('head meta[name="twitter:url"]', 0)->content;

            if (array_key_exists('categories', $item) === false) {
                $categories = $articlePage->find('div.go-tag-list__tags a.go-tag');
                $trimmedcategories = [];
                foreach ($categories as $category) {
                    $trimmedcategories[] = trim(html_entity_decode($category->plaintext));
                }
                if ($trimmedcategories !== []) {
                    $item['categories'] = array_unique($trimmedcategories);
                }
            }

            $nextUri = $articlePage->find('li.go-pagination__item--next a', 0);
            if ($nextUri !== null) {
                $uri = $nextUri->href;
            } else {
                $uri = null;
            }

            $item['content'] .= $this->extractContent($articlePage, $item['content']);
        }

        return $item;
    }

    private function extractContent($page, string $prevcontent): string
    {
        $item = '';

        $articleNode = $page->find('article', 0);
        if ($articleNode === null) {
            return '';
        }

        $articleHtml = $articleNode->outertext;

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $articleHtml . '</div>');
        libxml_use_internal_errors(false);

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return '';
        }

        $embedSrcs = [];
        foreach ($page->find('script') as $script) {
            if (preg_match_all('/type:\s*\"Embed(.*)urlPrivacy:/U', $script, $embeds) === 1) {
                foreach ($embeds[1] as $embed) {
                    if (preg_match('/src:\s*\"([^\"]+)\"/', $embed, $src) === 1) {
                        $embedSrcs[] = $src[1];
                    }
                }
            }
        }

        $placeholders = $wrapper->querySelectorAll('.go-embed-container');
        $placeholdersArray = iterator_to_array($placeholders);

        foreach (range(0, count($placeholdersArray) - 1) as $i) {
            if (array_key_exists($i, $embedSrcs) === true) {
                $src = $embedSrcs[$i];
                if (preg_match('/youtube(-nocookie)?\.com/', $src, $match) === 1) {
                    $placeholdersArray[$i]->innerHTML = handleYoutube($src);
                }
            }
        }

        foreach ($wrapper->querySelectorAll('.gvideofig') as $embedcontent) {
            $id = $embedcontent->getAttribute('id');
            if ($id !== null && preg_match('/gvideo_(.*)/', $id, $videoid) === 1) {
                $videoHtml = <<<EOT
                    <video class="rmp-object-fit-contain rmp-video" x-webkit-airplay="allow" controlslist="nodownload" tabindex="-1"
                    preload="metadata" src="https://video.golem.de/download/{$videoid[1]}"></video>
                EOT;
                $embedcontent->innerHTML .= $videoHtml;
            }
        }

        $badSelectors = [
            'div[id*="adtile"]',
            '#job-market',
            '#seminars',
            'iframe',
            '.go-article-header__title',
            '.go-article-header__kicker',
            '.go-label--sponsored',
            '.gbox_affiliate',
            'div.toc',
            '.go-button-bar',
            '.go-alink-list',
            '.go-teaser-block',
            '.go-vh',
            '.go-paywall',
            '.go-index',
            '.go-pagination__list',
            '.go-gallery [data-active="false"]',
            '.go-article-header__series',
            '.go-media__affiliate-img',
            '.go-info-box-container',
            '.go-ad-slot',
            '.go-media__credit',
            'figcaption',
            '.go-affiliate-disclosure__trigger'
        ];

        foreach ($badSelectors as $selector) {
            foreach ($wrapper->querySelectorAll($selector) as $bad) {
                $bad->remove();
            }
        }

        $firstHeader = $page->find('.table-jtoc td', 0);
        $firstHeaderText = null;
        if ($firstHeader !== null && $firstHeader->title !== null) {
            $firstHeaderText = html_entity_decode($firstHeader->title);
        }

        $multipageHeader = $wrapper->querySelector('header.paged-cluster-header h1');
        if ($multipageHeader !== null && $multipageHeader->textContent !== $firstHeaderText) {
            $item .= $dom->saveHTML($multipageHeader);
        }

        $header = $wrapper->querySelector('header');
        if ($header !== null) {
            foreach ($header->querySelectorAll('p, figure') as $element) {
                $item .= $dom->saveHTML($element);
            }
        }

        $contentSelectors = [
            'div.go-article-header__intro',
            'p',
            'h1',
            'h2',
            'h3',
            'pre',
            'ul',
            'ol',
            '.go-media img[src*="."]',
            'table',
            'iframe',
            'video',
            'img'
        ];

        $selectorString = implode(', ', $contentSelectors);

        foreach ($wrapper->querySelectorAll($selectorString) as $element) {
            $elementHtml = $dom->saveHTML($element);
            if (str_contains($prevcontent, $elementHtml) === false) {
                $item .= $elementHtml;
            }
        }

        return $item;
    }
}
