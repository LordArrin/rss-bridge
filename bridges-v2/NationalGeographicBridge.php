<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class NationalGeographicBridge extends BridgeAbstract
{
    public const NAME = 'National Geographic';
    public const URI = 'https://www.nationalgeographic.com/';
    public const DESCRIPTION = 'Fetches the latest articles from the National Geographic';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 7200;

    private const API_CONTEXT = 'eyJjb250ZW50VHlwZSI6IlVuaXNvbkh1YiIsInZhcmlhYmxlcyI6eyJsb2NhdG9yIjoiL3BhZ2VzL3RvcGljL2xhdGVzdC1zdG9yaWVzIiwicG9ydGZvbGlvIjoibmF0Z2VvIiwicXVlcnlUeXBlIjoiTE9DQVRPUiJ9LCJtb2R1bGVJZCI6bnVsbH0';

    private const LATEST_STORIES_IDS = [
        '1df278bb-0e3d-4a67-a0ce-8fae48392822-f2-m1',
    ];

    private const LATEST_STORIES_PATH = 'latest-stories';

    public const PARAMETERS = [
        [
            'full' => [
                'name' => 'Full Article',
                'type' => 'checkbox',
                'title' => 'Enable to load full articles',
            ],
        ],
    ];

    public function getURI(): string
    {
        return self::URI . self::LATEST_STORIES_PATH;
    }

    public function collectData(): void
    {
        $stories = [];

        foreach (self::LATEST_STORIES_IDS as $id) {
            $tiles = $this->fetchTiles($id);
            $stories = array_merge($stories, $tiles);
        }

        $loadFull = $this->isFullArticleEnabled();

        foreach ($stories as $story) {
            if (is_array($story) === false) {
                continue;
            }

            $item = $this->buildStoryItem($story, $loadFull);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }

        if ($this->items === []) {
            throwServerException('No stories could be extracted');
        }
    }

    private function fetchTiles(string $moduleId): array
    {
        $url = 'https://www.nationalgeographic.com/proxy/hub?context='
            . self::API_CONTEXT
            . '&id=' . $moduleId
            . '&moduleType=InfiniteFeedModule&_xhr=pageContent';

        $response = getContents($url);

        if ($response === '') {
            return [];
        }

        $data = json_decode($response, true);

        if (is_array($data) === false || isset($data['tiles']) === false || is_array($data['tiles']) === false) {
            return [];
        }

        return $data['tiles'];
    }

    private function isFullArticleEnabled(): bool
    {
        $input = $this->getInput('full');

        if ($input === true) {
            return true;
        }
        if ($input === 'on') {
            return true;
        }

        return false;
    }

    private function buildStoryItem(array $story, bool $loadFull): ?array
    {
        $title = $story['title'] ?? null;
        if ($title === null || is_string($title) === false || $title === '') {
            return null;
        }

        $uri = '';
        $storyType = '';
        $ctas = $story['ctas'] ?? [];
        if (is_array($ctas) === true) {
            foreach ($ctas as $component) {
                if (is_array($component) === false) {
                    continue;
                }
                if (isset($component['url']) === true && is_string($component['url']) === true) {
                    $uri = $component['url'];
                }
                if (isset($component['icon']) === true && is_string($component['icon']) === true) {
                    $storyType = $component['icon'];
                }
            }
        }

        if ($uri === '') {
            return null;
        }

        $description = $story['description'] ?? null;
        $content = '';
        if ($description !== null && is_string($description) === true && $description !== '') {
            $content = '<p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $imageSrc = $this->extractStoryImage($story);
        if ($imageSrc !== '') {
            $content = '<img src="' . htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8') . '">' . $content;
        }

        $categories = $this->extractStoryCategories($story);

        $item = [
            'uri' => $uri,
            'title' => $title,
            'content' => $content,
            'uid' => md5($uri),
        ];

        if ($categories !== []) {
            $item['categories'] = $categories;
        }

        if ($loadFull === true && $storyType !== 'interactive') {
            $articleData = $this->fetchFullArticle($uri);
            if ($articleData !== null) {
                $item['timestamp'] = $articleData['published_date'];
                $item['author'] = $articleData['authors'];
                $item['content'] = $content . $articleData['content'];
            }
        }

        return $item;
    }

    private function extractStoryImage(array $story): string
    {
        $img = $story['img'] ?? null;
        if (is_array($img) === false) {
            return '';
        }

        $src = $img['src'] ?? null;
        if ($src === null || is_string($src) === false || $src === '') {
            return '';
        }

        return str_replace(' ', '%20', $src);
    }

    private function extractStoryCategories(array $story): array
    {
        $categories = [];
        $tags = $story['tags'] ?? [];

        if (is_array($tags) === false) {
            return [];
        }

        foreach ($tags as $tag) {
            if (is_array($tag) === true && isset($tag['name']) === true && is_string($tag['name']) === true) {
                $categories[] = $tag['name'];
            } elseif (is_string($tag) === true && $tag !== '') {
                $categories[] = $tag;
            }
        }

        return $categories;
    }

    private function fetchFullArticle(string $uri): ?array
    {
        $html = getContents($uri);

        if ($html === '') {
            return null;
        }

        $scriptRegex = '/window\[\'__natgeo__\'\]=(.*);<\/script>/';
        $matchResult = preg_match($scriptRegex, $html, $matches);

        if ($matchResult === 0 || $matchResult === false) {
            return null;
        }

        $json = json_decode($matches[1], true);

        if (is_array($json) === false) {
            return null;
        }

        $frms = null;
        if (isset($json['page']['content']['article']['frms']) === true && is_array($json['page']['content']['article']['frms']) === true) {
            $frms = $json['page']['content']['article']['frms'];
        } elseif (isset($json['page']['content']['prismarticle']['frms']) === true && is_array($json['page']['content']['prismarticle']['frms']) === true) {
            $frms = $json['page']['content']['prismarticle']['frms'];
        }

        if ($frms === null) {
            return null;
        }

        $filteredData = $this->filterArticleData($frms);
        if ($filteredData === null) {
            return null;
        }

        $edgs = $filteredData['edgs'] ?? [];
        if (is_array($edgs) === false || $edgs === []) {
            return null;
        }

        $article = $edgs[0];
        if (is_array($article) === false) {
            return null;
        }

        $authors = $this->extractAuthors($article);
        $publishedDate = $article['pbDt'] ?? ($article['dt'] ?? null);

        $timestamp = time();
        if ($publishedDate !== null && is_string($publishedDate) === true) {
            $parsed = strtotime($publishedDate);
            if ($parsed !== false) {
                $timestamp = $parsed;
            }
        }

        $body = $article['bdy'] ?? [];
        $content = '';
        if (is_array($body) === true) {
            $content = $this->renderArticleBody($body);
        }

        return [
            'content' => $content,
            'published_date' => $timestamp,
            'authors' => $authors,
        ];
    }

    private function filterArticleData(array $data): ?array
    {
        foreach ($data as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $id = $item['id'] ?? null;
            if ($id !== 'natgeo-template1-frame-1') {
                continue;
            }

            $mods = $item['mods'] ?? [];
            if (is_array($mods) === false) {
                return null;
            }

            foreach ($mods as $mod) {
                if (is_array($mod) === true && ($mod['id'] ?? '') === 'natgeo-template1-frame-1-module-1') {
                    return $mod;
                }
            }
        }

        return null;
    }

    private function extractAuthors(array $article): string
    {
        $contributors = $article['cntrbGrp'] ?? [];
        if (is_array($contributors) === false || $contributors === []) {
            return '';
        }

        $firstGroup = $contributors[0];
        if (is_array($firstGroup) === false) {
            return '';
        }

        $authorList = $firstGroup['contributors'] ?? [];
        if (is_array($authorList) === false || $authorList === []) {
            return '';
        }

        $names = [];
        foreach ($authorList as $author) {
            if (is_array($author) === true && isset($author['displayName']) === true && is_string($author['displayName']) === true) {
                $names[] = $author['displayName'];
            }
        }

        return implode(', ', $names);
    }

    private function renderArticleBody(array $body): string
    {
        $content = '';

        foreach ($body as $block) {
            if (is_array($block) === false) {
                continue;
            }

            $type = $block['type'] ?? '';
            $cntnt = $block['cntnt'] ?? null;

            if ($type === 'p') {
                $markup = $cntnt['mrkup'] ?? '';
                if (is_string($markup) === true && $markup !== '') {
                    $content .= '<p>' . $markup . '</p>';
                }
            } elseif ($type === 'h2') {
                $markup = $cntnt['mrkup'] ?? '';
                if (is_string($markup) === true && $markup !== '') {
                    $content .= '<h2>' . $markup . '</h2>';
                }
            } elseif ($type === 'ul') {
                $markup = $cntnt['mrkup'] ?? '';
                if (is_string($markup) === true && $markup !== '') {
                    $content .= $markup . '<hr>';
                }
            } elseif ($type === 'inline') {
                if (is_array($cntnt) === false || $cntnt === []) {
                    continue;
                }
                $content .= $this->renderInlineModule($cntnt);
            }
        }

        return $content;
    }

    private function renderInlineModule(array $module): string
    {
        $cmsType = $module['cmsType'] ?? '';

        switch ($cmsType) {
            case 'image':
                return $this->renderImage($module, 'image');
            case 'imagegroup':
                return $this->renderImageGroup($module);
            case 'editorsNote':
                $note = $module['note'] ?? '';
                if (is_string($note) === true) {
                    return $note;
                }
                return '';
            case 'listicle':
                return $this->renderListicle($module);
            case 'photogallery':
                return $this->renderPhotoGallery($module);
            case 'video':
                return $this->renderImage($module, 'video');
            case 'pullquote':
                return $this->renderPullQuote($module);
            default:
                return '';
        }
    }

    private function renderImage(array $module, string $imageType): string
    {
        $imageSrc = '';
        $imageAlt = '';
        $imageCredit = '';
        $caption = '';

        if ($imageType === 'image' || $imageType === 'imagegroup') {
            $image = $module['image'] ?? null;
            if (is_array($image) === false) {
                return '';
            }

            $imageSrc = $image['src'] ?? '';
            $imageAlt = $module['alt'] ?? ($image['altText'] ?? '');
            $imageCredit = $image['crdt'] ?? '';
            $caption = $module['caption'] ?? '';
        } elseif ($imageType === 'video') {
            $imageCredit = $module['credit'] ?? '';
            $description = $module['description'] ?? '';
            $caption = $description . " Video can be watched on the article's page";

            $image = $module['image'] ?? null;
            if (is_array($image) === false) {
                return '';
            }

            $imageAlt = $image['altText'] ?? '';
            $imageSrc = $image['src'] ?? '';
        }

        if ($imageSrc === '' || is_string($imageSrc) === false) {
            return '';
        }

        $fullCaption = trim($caption . ' ' . $imageCredit);
        if ($fullCaption !== '') {
            $fullCaption .= '. Notes: Some image may have copyrighted on it.';
        }

        $safeSrc = htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8');
        $safeAlt = htmlspecialchars((string) $imageAlt, ENT_QUOTES, 'UTF-8');
        $safeCaption = htmlspecialchars($fullCaption, ENT_QUOTES, 'UTF-8');

        $html = '<figure>';
        $html .= '<img src="' . $safeSrc . '" alt="' . $safeAlt . '">';
        $html .= '<figcaption>' . $safeCaption . '</figcaption>';
        $html .= '</figure>';

        return $html;
    }

    private function renderImageGroup(array $module): string
    {
        $images = $module['images'] ?? [];
        if (is_array($images) === false) {
            return '';
        }

        $html = '';
        foreach ($images as $image) {
            if (is_array($image) === true) {
                $html .= $this->renderImage($image, 'imagegroup');
            }
        }

        return $html;
    }

    private function renderListicle(array $module): string
    {
        $title = $module['title'] ?? '(no title)';
        $html = '<h2>' . htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') . '</h2>';

        $image = $module['image'] ?? null;
        if (is_array($image) === true) {
            $cmsType = $image['cmsType'] ?? 'image';
            $html .= $this->renderImage($image, (string) $cmsType);
        }

        $text = $module['text'] ?? '';
        if (is_string($text) === true && $text !== '') {
            $html .= '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return $html;
    }

    private function renderPhotoGallery(array $module): string
    {
        $media = $module['media'] ?? [];
        if (is_array($media) === false) {
            return '';
        }

        $html = '';
        foreach ($media as $image) {
            if (is_array($image) === true) {
                $imageSrc = $image['img']['src'] ?? '';
                $imageAlt = $image['img']['altText'] ?? '';
                $imageCredit = $image['caption']['credit'] ?? '';
                $captionText = $image['caption']['text'] ?? '';

                if ($imageSrc === '' || is_string($imageSrc) === false) {
                    continue;
                }

                $fullCaption = trim($captionText . ' ' . $imageCredit);

                $safeSrc = htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8');
                $safeAlt = htmlspecialchars((string) $imageAlt, ENT_QUOTES, 'UTF-8');
                $safeCaption = htmlspecialchars($fullCaption, ENT_QUOTES, 'UTF-8');

                $html .= '<figure>';
                $html .= '<img src="' . $safeSrc . '" alt="' . $safeAlt . '">';
                $html .= '<figcaption>' . $safeCaption . '</figcaption>';
                $html .= '</figure>';
            }
        }

        return $html;
    }

    private function renderPullQuote(array $module): string
    {
        $quote = $module['quote'] ?? '';
        if (is_string($quote) === false || $quote === '') {
            return '';
        }

        $authorName = '';
        $authors = $module['byLineProps']['authors'] ?? [];
        if (is_array($authors) === true) {
            $nameParts = [];
            foreach ($authors as $author) {
                if (is_array($author) === false) {
                    continue;
                }
                $displayName = $author['displayName'] ?? '';
                $authorDesc = $author['authorDesc'] ?? '';
                $part = trim($displayName . ', ' . $authorDesc, ', ');
                if ($part !== '') {
                    $nameParts[] = $part;
                }
            }
            $authorName = implode(', ', $nameParts);
        }

        $safeQuote = htmlspecialchars($quote, ENT_QUOTES, 'UTF-8');
        $safeAuthor = htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8');

        $html = '<figure>';
        $html .= '<blockquote><p>' . $safeQuote . '</p></blockquote>';
        if ($safeAuthor !== '') {
            $html .= '<figcaption>' . $safeAuthor . '</figcaption>';
        }
        $html .= '</figure>';

        return $html;
    }
}
