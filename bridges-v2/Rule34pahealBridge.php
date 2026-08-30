<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class Rule34pahealBridge extends BridgeAbstract
{
    public const NAME = 'Rule34paheal';
    public const URI = 'https://rule34.paheal.net/';
    public const DESCRIPTION = 'Returns images from rule34.paheal.net search';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'p' => [
                'name' => 'Page',
                'type' => 'number',
                'defaultValue' => 1,
            ],
            't' => [
                'name' => 'Tags',
                'type' => 'text',
                'exampleValue' => 'cosplay',
                'required' => false,
                'title' => 'Tags separated by spaces or commas',
            ],
            'limit' => [
                'name' => 'Posts limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum number of posts to return',
            ],
            'hide_categories' => [
                'name' => 'Hide categories',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Do not include tags as RSS categories',
            ],
        ]
    ];

    private const PATHTODATA = '.shm-thumb';
    private const IDATTRIBUTE = 'data-post-id';

    public function collectData(): void
    {
        $url = $this->getFullURI();
        $html = getContents($url);
        if ($html === '') {
            throwServerException(sprintf('Failed to fetch %s', $url));
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $limit = (int)($this->getInput('limit') ?? 10);
        if ($limit <= 0) {
            $limit = 10;
        }

        $hideCategories = $this->getInput('hide_categories') === true;

        $elements = $dom->querySelectorAll(self::PATHTODATA);
        $count = 0;

        foreach ($elements as $element) {
            if ($count >= $limit) {
                break;
            }

            $item = $this->getItemFromElement($element, $hideCategories);
            if ($item !== null) {
                $this->items[] = $item;
                $count++;
            }
        }
    }

    private function getFullURI(): string
    {
        $page = (int)($this->getInput('p') ?? 1);
        $tags = $this->normalizeTags((string)($this->getInput('t') ?? ''));

        return self::URI . 'post/list/' . rawurlencode($tags) . '/' . $page;
    }

    private function normalizeTags(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $parts = preg_split('/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return '';
        }

        $tags = [];
        foreach ($parts as $part) {
            $tag = trim($part);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return implode(' ', $tags);
    }

    private function getItemFromElement(\Dom\Element $element, bool $hideCategories): ?array
    {
        $titleLink = $element->querySelector('.shm-thumb-link');
        if ($titleLink === null) {
            return null;
        }

        $postHref = $titleLink->getAttribute('href') ?? '';
        if ($postHref === '') {
            return null;
        }

        $idAttr = $element->getAttribute(self::IDATTRIBUTE) ?? '';
        $postId = (int)preg_replace('/[^0-9]/', '', $idAttr);
        if ($postId === 0) {
            return null;
        }

        $allLinks = $element->querySelectorAll('a');
        $linksArray = iterator_to_array($allLinks);
        if (count($linksArray) < 2) {
            return null;
        }

        $thumbnailHref = $linksArray[1]->getAttribute('href') ?? '';
        $thumbnailUri = $this->makeAbsoluteUrl($thumbnailHref);
        if ($thumbnailUri === '') {
            return null;
        }

        $postUri = $this->makeAbsoluteUrl($postHref);
        if ($postUri === '') {
            return null;
        }

        $dataTags = $element->getAttribute('data-tags') ?? '';
        $categories = array_values(array_filter(explode(' ', $dataTags)));

        $title = 'Image ' . $postId;

        $escapedUri = htmlspecialchars($postUri, ENT_QUOTES, 'UTF-8');
        $escapedThumbnail = htmlspecialchars($thumbnailUri, ENT_QUOTES, 'UTF-8');

        $content = '<a href="' . $escapedUri . '"><img src="' . $escapedThumbnail . '" /></a>';

        $item = [
            'uri' => $postUri,
            'timestamp' => time(),
            'title' => $title,
            'content' => $content,
            'uid' => 'rule34paheal-' . $postId,
        ];

        if ($hideCategories === false && $categories !== []) {
            $item['categories'] = $categories;
        }

        return $item;
    }

    private function makeAbsoluteUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, 'http://') === true || str_starts_with($url, 'https://') === true) {
            return $url;
        }

        if (str_starts_with($url, '//') === true) {
            return 'https:' . $url;
        }

        $base = rtrim(self::URI, '/');

        if (str_starts_with($url, '/') === true) {
            return $base . $url;
        }

        return $base . '/' . $url;
    }

    public function getName(): string
    {
        $tags = $this->normalizeTags((string)($this->getInput('t') ?? ''));
        if ($tags !== '') {
            return $tags;
        }
        return parent::getName();
    }
}
