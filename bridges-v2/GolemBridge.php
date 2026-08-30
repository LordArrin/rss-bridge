<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

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

    protected function parseItem(array $item): array|false
    {
        $item['content'] ??= '';
        $uri = $item['uri'];

        $urls = [];

        while ($uri !== null) {
            if (isset($urls[$uri]) === true) {
                break;
            }
            $urls[$uri] = true;

            $html = getContents($uri, static::HEADERS);
            libxml_use_internal_errors(true);
            $articlePage = \Dom\HTMLDocument::createFromString($html);
            libxml_use_internal_errors(false);

            $this->convertRelativeToAbsoluteLinks($articlePage, $uri);

            $twitterUrl = $articlePage->querySelector('head meta[name="twitter:url"]');
            if ($twitterUrl !== null) {
                $item['uri'] = $twitterUrl->getAttribute('content') ?? $uri;
            }

            if (array_key_exists('categories', $item) === false) {
                $categories = $articlePage->querySelectorAll('div.go-tag-list__tags a.go-tag');
                $trimmedcategories = [];
                foreach ($categories as $category) {
                    $trimmedcategories[] = trim(html_entity_decode($category->textContent ?? ''));
                }
                if ($trimmedcategories !== []) {
                    $item['categories'] = array_unique($trimmedcategories);
                }
            }

            $nextUri = $articlePage->querySelector('li.go-pagination__item--next a');
            if ($nextUri !== null) {
                $uri = $nextUri->getAttribute('href');
            } else {
                $uri = null;
            }

            $item['content'] .= $this->extractContent($articlePage, $item['content']);
        }

        return $item;
    }

    private function extractContent(\Dom\HTMLDocument $page, string $prevcontent): string
    {
        $item = '';

        $articleNode = $page->querySelector('article');
        if ($articleNode === null) {
            return '';
        }

        $articleHtml = $articleNode->outerHTML;

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $articleHtml . '</div>');
        libxml_use_internal_errors(false);

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return '';
        }

        $embedSrcs = [];
        foreach ($page->querySelectorAll('script') as $script) {
            $scriptContent = $script->textContent ?? '';
            if (preg_match_all('/type:\s*\"Embed(.*)urlPrivacy:/U', $scriptContent, $embeds) === 1) {
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

        $firstHeader = $page->querySelector('.table-jtoc td');
        $firstHeaderText = null;
        if ($firstHeader !== null) {
            $titleAttr = $firstHeader->getAttribute('title');
            if ($titleAttr !== null) {
                $firstHeaderText = html_entity_decode($titleAttr);
            }
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

    /**
     * Convert relative URLs to absolute URLs for img src and a href attributes
     */
    private function convertRelativeToAbsoluteLinks(\Dom\HTMLDocument $dom, string $baseUrl): void
    {
        // Process images
        foreach ($dom->querySelectorAll('img') as $image) {
            $src = $image->getAttribute('src');
            if ($src !== null && $src !== '') {
                $image->setAttribute('src', urljoin($baseUrl, $src));
            }
        }

        // Process anchors
        foreach ($dom->querySelectorAll('a') as $anchor) {
            $href = $anchor->getAttribute('href');
            if ($href !== null && $href !== '') {
                $anchor->setAttribute('href', urljoin($baseUrl, $href));
            }
        }
    }
}
