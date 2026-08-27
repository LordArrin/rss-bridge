<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class PikabuBridge extends BridgeAbstract
{
    public const NAME = 'Pikabu';
    public const URI = 'https://pikabu.ru';
    public const DESCRIPTION = 'Displays posts by tag, community, or user';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const PARAMETERS_FILTER = [
        'name' => 'Фильтр',
        'type' => 'list',
        'values' => [
            'Горячее' => 'hot',
            'Свежее' => 'new',
        ],
        'defaultValue' => 'hot',
    ];

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    public const PARAMETERS = [
        'По тегу' => [
            'tag' => [
                'name' => 'Тег',
                'exampleValue' => 'it',
                'required' => true,
            ],
            'filter' => self::PARAMETERS_FILTER,
        ],
        'По сообществу' => [
            'community' => [
                'name' => 'Сообщество',
                'exampleValue' => 'linux',
                'required' => true,
            ],
            'filter' => self::PARAMETERS_FILTER,
        ],
        'По пользователю' => [
            'user' => [
                'name' => 'Пользователь',
                'exampleValue' => 'admin',
                'required' => true,
            ],
        ],
    ];

    private ?string $feedTitle = null;

    public function getURI()
    {
        $tag = $this->getInput('tag');
        $user = $this->getInput('user');
        $community = $this->getInput('community');
        $filter = $this->getInput('filter');

        if ($tag !== null && $tag !== '') {
            return self::URI . '/tag/' . rawurlencode((string)$tag) . '/' . rawurlencode((string)$filter);
        }

        if ($user !== null && $user !== '') {
            return self::URI . '/@' . rawurlencode((string)$user);
        }

        if ($community !== null && $community !== '') {
            $uri = self::URI . '/community/' . rawurlencode((string)$community);
            if ($filter !== 'hot') {
                $uri .= '/' . rawurlencode((string)$filter);
            }
            return $uri;
        }

        return parent::getURI();
    }

    public function getIcon()
    {
        return 'https://cs.pikabu.ru/assets/favicon.ico';
    }

    public function getName()
    {
        if ($this->feedTitle === null) {
            return parent::getName();
        }

        return $this->feedTitle . ' - ' . parent::getName();
    }

    public function collectData()
    {
        $link = $this->getURI();
        $rawHtml = getContents($link);

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($rawHtml);
        libxml_use_internal_errors(false);

        $titleEl = $dom->querySelector('title');
        if ($titleEl !== null) {
            $this->feedTitle = trim($titleEl->textContent ?? '');
        }

        $posts = $dom->querySelectorAll('article.story');
        foreach ($posts as $post) {
            $time = $post->querySelector('time.story__datetime');
            if ($time === null) {
                $time = $post->querySelector('time');
            }
            if ($time === null) {
                continue;
            }

            $titleEl = $post->querySelector('.story__title-link');
            if ($titleEl === null) {
                $titleEl = $post->querySelector('.story__title a');
            }
            if ($titleEl === null) {
                $titleEl = $post->querySelector('a.story__title-link');
            }
            if ($titleEl === null) {
                continue;
            }

            $titleHref = $titleEl->getAttribute('href') ?? '';
            if (str_contains($titleHref, 'from=cpm') === true) {
                continue;
            }

            $this->removeUnwantedElements($post);
            $this->replaceGifxWithImg($post);
            $this->replaceLazyImages($post);
            $this->fixImages($post);

            $categories = $this->extractCategories($post);

            $title = trim($titleEl->textContent ?? '');

            $communityLink = $post->querySelector('.story__community-link');
            if ($communityLink !== null && $communityLink->getAttribute('href') === '/community/maybenews') {
                $communityTitle = trim($communityLink->textContent ?? '');
                if ($communityTitle !== '') {
                    $title = '[' . $communityTitle . '] ' . $title;
                }
            }

            $authorEl = $post->querySelector('.user__nick');
            $author = $authorEl !== null ? trim($authorEl->textContent ?? '') : null;

            $contentInner = $post->querySelector('.story__content-inner');
            if ($contentInner === null) {
                $contentInner = $post->querySelector('.story__content');
            }

            $contentHtml = '';
            if ($contentInner !== null) {
                $contentHtml = $contentInner->innerHTML ?? '';
                $contentHtml = strip_tags($contentHtml, '<br><p><img><a><s>');
            }

            $datetime = $time->getAttribute('datetime') ?? '';
            $timestamp = null;
            if ($datetime !== '') {
                $parsed = strtotime($datetime);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }

            $uri = $titleHref;
            if ($uri !== '' && str_starts_with($uri, '/') === true) {
                $uri = self::URI . $uri;
            }

            $item = [
                'categories' => $categories,
                'author' => ($author !== null && $author !== '') ? $author : null,
                'title' => $title,
                'content' => $contentHtml,
                'uri' => $uri,
                'timestamp' => $timestamp,
            ];

            $this->items[] = $item;
        }
    }

    private function removeUnwantedElements(\Dom\Element $post): void
    {
        $selectors = [
            '.story__read-more',
            'script',
            'svg.story-image__stretch',
        ];

        foreach ($selectors as $selector) {
            $elements = iterator_to_array($post->querySelectorAll($selector));
            foreach ($elements as $el) {
                if ($el->parentNode !== null) {
                    $el->parentNode->removeChild($el);
                }
            }
        }
    }

    private function replaceGifxWithImg(\Dom\Element $post): void
    {
        $gifxes = iterator_to_array($post->querySelectorAll('[data-type=gifx]'));
        foreach ($gifxes as $el) {
            $src = $el->getAttribute('data-source');
            if ($src !== null && $src !== '' && $el->parentNode !== null) {
                $img = $el->ownerDocument->createElement('img');
                $img->setAttribute('src', $src);
                $el->parentNode->replaceChild($img, $el);
            }
        }
    }

    private function replaceLazyImages(\Dom\Element $post): void
    {
        $images = iterator_to_array($post->querySelectorAll('img'));
        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            if ($src === null || $src === '') {
                $src = $img->getAttribute('data-src');
                if ($src === null || $src === '') {
                    continue;
                }
            }

            $img->setAttribute('src', $src);

            $parent = $img->parentNode;
            if ($parent !== null && $parent instanceof \Dom\Element && $parent->tagName === 'a') {
                if ($parent->parentNode !== null) {
                    $parent->parentNode->replaceChild($img, $parent);
                }
            }
        }
    }

    private function fixImages(\Dom\Element $post): void
    {
        $images = iterator_to_array($post->querySelectorAll('img'));
        foreach ($images as $img) {
            if ($img instanceof \Dom\Element) {
                $img->setAttribute('style', self::CSS['image']);
            }
        }
    }

    private function extractCategories(\Dom\Element $post): array
    {
        $categories = [];
        $tags = $post->querySelectorAll('.tags__tag');
        foreach ($tags as $tag) {
            if ($tag->getAttribute('data-tag') !== null) {
                $text = trim($tag->textContent ?? '');
                if ($text !== '') {
                    $categories[] = $text;
                }
            }
        }
        return $categories;
    }
}
