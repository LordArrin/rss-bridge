<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;
use RSSBridge\Utils\Json;

use function urljoin;

final class NautilusBridge extends FeedExpander
{
    public const NAME = 'Nautilus';
    public const URI = 'https://nautil.us/';
    public const DESCRIPTION = 'Returns the latest articles from nautil.us';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'limit' => [
                'name' => 'Feed Item Limit',
                'required' => true,
                'type' => 'number',
                'exampleValue' => 10,
                'defaultValue' => 10,
                'title' => 'Maximum number of returned feed items. Default 10',
            ],
            'topic' => [
                'name' => 'Topics and Channels',
                'required' => false,
                'title' => 'Select a topic',
                'type' => 'list',
                'defaultValue' => 'default',
                'values' => [
                    'All items' => 'default',
                    'Topic: Anthropology' => 'topics/anthropology',
                    'Topic: Arts' => 'topics/arts',
                    'Topic: Astronomy' => 'topics/astronomy',
                    'Topic: Communication' => 'topics/communication',
                    'Topic: Economics' => 'topics/economics',
                    'Topic: Environment' => 'topics/environment',
                    'Topic: Evolution' => 'topics/evolution',
                    'Topic: General' => 'topics/general',
                    'Topic: Genetics' => 'topics/genetics',
                    'Topic: Geoscience' => 'topics/geoscience',
                    'Topic: Health' => 'topics/health',
                    'Topic: History' => 'topics/history',
                    'Topic: Math' => 'topics/math',
                    'Topic: Microbiology' => 'topics/microbiology',
                    'Topic: Neuroscience' => 'topics/neuroscience',
                    'Topic: Paleontology' => 'topics/paleontology',
                    'Topic: Philosophy' => 'topics/philosophy',
                    'Topic: Physics' => 'topics/physics',
                    'Topic: Psychology' => 'topics/psychology',
                    'Topic: Sociology' => 'topics/sociology',
                    'Topic: Technology' => 'topics/technology',
                    'Topic: Zoology' => 'topics/zoology',
                    'Channel: Art + Science' => 'channel/art-science',
                    'Channel: Biology + Beyond' => 'channel/bilology-beyond',
                    'Channel: Catalysts of Discovery' => 'channel/catalysts',
                    'Channel: Cosmos' => 'channel/cosmos',
                    'Channel: Culture' => 'channel/culture',
                    'Channel: Currents' => 'channel/currents',
                    'Channel: Earth' => 'channel/earth',
                    'Channel: Life' => 'channel/life',
                    'Channel: Mind' => 'channel/mind',
                    'Channel: Ocean' => 'channel/ocean',
                    'Channel: One Question' => 'channel/one-question',
                    'Channel: Quanta Abstractions' => 'channel/abstractions',
                    'Channel: Rewilding' => 'channel/rewilding',
                    'Channel: Science at the Ballot Box' => 'channel/ballotbox-science',
                    'Channel: Science Philanthropy Alliance' => 'channel/alliance',
                    'Channel: Spart of Science' => 'channel/spark',
                    'Channel: The Animal Issue' => 'channel/animal',
                    'Channel: The Climates Issue' => 'channel/climates',
                    'Channel: The Food Issue' => 'channel/food',
                    'Channel: The Kinship Issue' => 'channel/kinship',
                    'Channel: The Porthole' => 'channel/the-porthole',
                    'Channel: The Reality Issue' => 'channel/reality',
                    'Channel: The Rebel Issue' => 'channel/rebel',
                    'Channel: Women in Science & Engineering' => 'channel/wise',
                ],
            ],
        ],
    ];

    public function collectData(): void
    {
        $uri = self::URI;
        $topic = (string) $this->getInput('topic');

        if ($topic !== '' && $topic !== 'default') {
            $uri .= $topic . '/';
        }

        $uri .= 'feed';

        $limitInput = $this->getInput('limit');
        $limit = is_numeric($limitInput) === true ? (int) $limitInput : 10;

        $this->collectExpandableDatas($uri, $limit);
    }

    protected function parseItem(array $item): array
    {
        $articleUrl = rtrim((string) $item['uri'], '/');
        $cacheKey = 'article_' . md5($articleUrl);
        $cachedHtml = $this->loadCacheValue($cacheKey);

        if (is_string($cachedHtml) === false || $cachedHtml === '') {
            $html = (string) getContents($articleUrl);
            if ($html === '') {
                return $item;
            }
            $this->saveCacheValue($cacheKey, $html, 7 * 24 * 60 * 60);
            try {
                libxml_use_internal_errors(true);
                $dom = \Dom\HTMLDocument::createFromString($html);
                libxml_use_internal_errors(false);
            } catch (\Throwable) {
                libxml_use_internal_errors(false);
                return $item;
            }
        } else {
            try {
                libxml_use_internal_errors(true);
                $dom = \Dom\HTMLDocument::createFromString($cachedHtml);
                libxml_use_internal_errors(false);
            } catch (\Throwable) {
                libxml_use_internal_errors(false);
                return $item;
            }
        }

        $nextData = $this->extractNextData($dom);
        if ($nextData !== null) {
            $content = $this->parseNextDataContent($nextData);
            if ($content !== '') {
                $item['content'] = $content;
                return $item;
            }
        }

        $content = '';
        $featureImage = $dom->querySelector('img.article-banner-img');
        if ($featureImage instanceof \Dom\Element === true) {
            $src = $featureImage->getAttribute('src') ?? '';
            if ($src !== '') {
                $imgStyle = 'display: block !important; max-width: 1600px !important; width: auto !important; height: auto !important; float: none !important; clear: both !important;';
                $content .= '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE) . '" style="' . $imgStyle . '">';
            }
        }

        $articleMain = $dom->querySelector('div.article-content');
        if ($articleMain instanceof \Dom\Element === false) {
            $item['content'] = $content;
            return $item;
        }

        $content .= $this->prepareArticleContent($articleMain);
        $item['content'] = $content;

        return $item;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractNextData(\Dom\HTMLDocument $dom): ?array
    {
        $script = $dom->querySelector('script#__NEXT_DATA__');
        if ($script instanceof \Dom\Element === false) {
            return null;
        }

        $jsonString = $script->textContent ?? '';
        if ($jsonString === '') {
            return null;
        }

        try {
            $decoded = Json::decode(html_entity_decode($jsonString, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (is_array($decoded) === true) {
                return $decoded;
            }
            return null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $nextData
     */
    private function parseNextDataContent(array $nextData): string
    {
        $pageProps = is_array($nextData['props']['pageProps'] ?? null) === true ? $nextData['props']['pageProps'] : [];
        $content = '';

        $featuredImage = is_array($pageProps['post']['featuredImage']['node'] ?? null) === true ? $pageProps['post']['featuredImage']['node'] : [];
        $featuredImageUrl = is_string($featuredImage['sourceUrl'] ?? null) === true ? $featuredImage['sourceUrl'] : '';

        if ($featuredImageUrl !== '') {
            $imgStyle = 'display: block !important; max-width: 1600px !important; width: auto !important; height: auto !important; float: none !important; clear: both !important;';
            $content .= '<img src="' . htmlspecialchars($featuredImageUrl, ENT_QUOTES | ENT_SUBSTITUTE) . '" style="' . $imgStyle . '"';
            $featuredImageAlt = trim($featuredImage['altText'] ?? '');
            if ($featuredImageAlt !== '') {
                $content .= ' alt="' . htmlspecialchars($featuredImageAlt, ENT_QUOTES | ENT_SUBSTITUTE) . '"';
            }
            $content .= '>';
        }

        $blocksRaw = is_array($pageProps['blocks'] ?? null) === true ? $pageProps['blocks'] : (is_array($pageProps['post']['contentBlocks']['blocks'] ?? null) === true ? $pageProps['post']['contentBlocks']['blocks'] : []);

        $blocks = array_values(array_filter($blocksRaw, fn($v) => is_array($v)));

        if (count($blocks) === 0) {
            return $content;
        }

        $articleHtml = $this->renderBlocks($blocks);
        if ($articleHtml === '') {
            return $content;
        }

        try {
            libxml_use_internal_errors(true);
            $tempDom = \Dom\HTMLDocument::createFromString('<div>' . $articleHtml . '</div>');
            libxml_use_internal_errors(false);
        } catch (\Throwable) {
            libxml_use_internal_errors(false);
            return $content . $this->fixRelativeLinksInString($articleHtml);
        }

        $articleMain = $tempDom->querySelector('div');

        if ($articleMain instanceof \Dom\Element === false) {
            return $content . $this->fixRelativeLinksInString($articleHtml);
        }

        $cleanedContent = $this->prepareArticleContent($articleMain);
        return $content . $cleanedContent;
    }

    private function prepareArticleContent(\Dom\Element $articleMain): string
    {
        foreach ($articleMain->querySelectorAll('iframe') as $iframe) {
            if ($iframe instanceof \Dom\Element === false) {
                continue;
            }
            $src = $iframe->getAttribute('src') ?? '';
            $ownerDocument = $articleMain->ownerDocument;
            if ($src !== '' && $ownerDocument !== null) {
                $link = $ownerDocument->createElement('a');
                $link->textContent = $src;
                $link->setAttribute('href', $src);
                $iframe->replaceWith($link);
            }
        }

        $this->fixRelativeLinksInElement($articleMain);

        $selectorsToRemove = [
            'div.article-ad',
            'div.primis-ad',
            'div.article-collection_box',
            'img[src*="nautilus-favicon-14.png"]',
        ];

        foreach ($selectorsToRemove as $selector) {
            foreach ($articleMain->querySelectorAll($selector) as $node) {
                if ($node instanceof \Dom\Element === true) {
                    $node->remove();
                }
            }
        }

        $figures = $articleMain->querySelectorAll('figure');
        foreach ($figures as $figure) {
            if ($figure instanceof \Dom\Element === false) {
                continue;
            }
            $img = $figure->querySelector('img');
            if ($img instanceof \Dom\Element === false) {
                $figure->remove();
                continue;
            }

            $img->removeAttribute('class');
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->removeAttribute('sizes');
            $img->removeAttribute('style');

            $newStyle = 'display: block !important; max-width: 1600px !important; width: auto !important; height: auto !important; float: none !important; clear: both !important;';
            $img->setAttribute('style', $newStyle);

            $figure->replaceWith($img);
        }

        foreach ($articleMain->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === false) {
                continue;
            }

            $parent = $img->parentElement;
            if ($parent instanceof \Dom\Element === true && $parent->localName === 'figure') {
                continue;
            }

            $img->removeAttribute('class');
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->removeAttribute('sizes');
            $img->removeAttribute('style');

            $newStyle = 'display: block !important; max-width: 1600px !important; width: auto !important; height: auto !important; float: none !important; clear: both !important;';
            $img->setAttribute('style', $newStyle);
        }

        foreach ($articleMain->querySelectorAll('p') as $paragraph) {
            if ($paragraph instanceof \Dom\Element === false) {
                continue;
            }
            $text = trim(html_entity_decode($paragraph->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (str_contains($text, 'Subscribe to our free newsletter') === true) {
                $paragraph->remove();
            }
        }

        return $articleMain->innerHTML ?? '';
    }

    private function fixRelativeLinksInElement(\Dom\Element $element): void
    {
        foreach ($element->querySelectorAll('a[href], img[src], source[src]') as $node) {
            if ($node instanceof \Dom\Element === false) {
                continue;
            }

            if ($node->localName === 'a') {
                $href = $node->getAttribute('href');
                if ($href !== null && $href !== '' && str_starts_with($href, 'http') === false) {
                    $node->setAttribute('href', urljoin(self::URI, $href));
                }
            } elseif ($node->localName === 'img' || $node->localName === 'source') {
                $src = $node->getAttribute('src');
                if ($src !== null && $src !== '' && str_starts_with($src, 'http') === false) {
                    $node->setAttribute('src', urljoin(self::URI, $src));
                }
            }
        }
    }

    private function fixRelativeLinksInString(string $html): string
    {
        try {
            libxml_use_internal_errors(true);
            $tempDom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
            libxml_use_internal_errors(false);
        } catch (\Throwable) {
            libxml_use_internal_errors(false);
            return $html;
        }

        $wrapper = $tempDom->querySelector('div');
        if ($wrapper instanceof \Dom\Element === true) {
            $this->fixRelativeLinksInElement($wrapper);
            return $wrapper->innerHTML ?? $html;
        }
        return $html;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function renderBlocks(array $blocks): string
    {
        $html = '';

        foreach ($blocks as $block) {
            if (is_array($block) === false) {
                continue;
            }

            if (isset($block['innerHTML']) === true && $block['innerHTML'] !== '' && is_string($block['innerHTML']) === true) {
                $html .= $block['innerHTML'];
                continue;
            }

            if (isset($block['innerBlocks']) === true && is_array($block['innerBlocks']) === true) {
                $innerBlocks = array_values(array_filter($block['innerBlocks'], fn($v) => is_array($v)));
                $html .= $this->renderBlocks($innerBlocks);
                continue;
            }

            if (isset($block['blocks']) === true && is_array($block['blocks']) === true) {
                $subBlocks = array_values(array_filter($block['blocks'], fn($v) => is_array($v)));
                $html .= $this->renderBlocks($subBlocks);
            }
        }

        return $html;
    }
}
