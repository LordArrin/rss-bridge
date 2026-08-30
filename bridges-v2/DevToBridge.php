<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class DevToBridge extends BridgeAbstract
{
    public const NAME = 'dev.to';
    public const URI = 'https://dev.to';
    public const DESCRIPTION = 'Returns feeds for tags';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 10800;

    public const CONTEXT_BY_TAG = 'By tag';
    public const CONTEXT_BY_USER = 'By user';

    public const PARAMETERS = [
        self::CONTEXT_BY_TAG => [
            'tag' => [
                'name' => 'Tag',
                'type' => 'text',
                'required' => true,
                'title' => 'Insert your tag',
                'exampleValue' => 'python'
            ],
            'full' => [
                'name' => 'Full article',
                'type' => 'checkbox',
                'required' => false,
                'title' => 'Enable to receive the full article for each item'
            ],
            'hide_categories' => [
                'name' => 'Hide categories',
                'type' => 'checkbox',
                'required' => false,
                'title' => 'Hide tags/categories from feed items',
                'defaultValue' => 'checked'
            ]
        ],
        self::CONTEXT_BY_USER => [
            'user' => [
                'name' => 'User',
                'type' => 'text',
                'required' => true,
                'title' => 'Insert your username',
                'exampleValue' => 'n3wt0n'
            ],
            'full' => [
                'name' => 'Full article',
                'type' => 'checkbox',
                'required' => false,
                'title' => 'Enable to receive the full article for each item'
            ],
            'hide_categories' => [
                'name' => 'Hide categories',
                'type' => 'checkbox',
                'required' => false,
                'title' => 'Hide tags/categories from feed items',
                'defaultValue' => 'checked'
            ]
        ]
    ];

    public function getURI(): string
    {
        switch ($this->queriedContext) {
            case self::CONTEXT_BY_TAG:
                $tag = $this->getInput('tag');
                if ($tag !== null && $tag !== '') {
                    return self::URI . '/t/' . urlencode((string)$tag);
                }
                break;
            case self::CONTEXT_BY_USER:
                $user = $this->getInput('user');
                if ($user !== null && $user !== '') {
                    return self::URI . '/' . urlencode((string)$user);
                }
                break;
        }

        return parent::getURI();
    }

    public function getIcon(): string
    {
        return 'https://practicaldev-herokuapp-com.freetls.fastly.net/assets/apple-icon-5c6fa9f2bce280428589c6195b7f1924206a53b782b371cfe2d02da932c8c173.png';
    }

    public function collectData(): void
    {
        $uri = $this->getURI();
        $html = getContents($uri);
        if ($html === '') {
            throwServerException('Could not fetch: ' . $uri);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($dom->documentElement);

        $articles = $dom->querySelectorAll('div.crayons-story');
        if ($articles->length === 0) {
            throwServerException('Could not find articles!');
        }

        foreach ($articles as $article) {
            $item = [];

            $articleLink = $article->querySelector('a[id*="article-link"]');
            if ($articleLink === null) {
                continue;
            }
            $item['uri'] = $articleLink->getAttribute('href') ?? '';
            if ($item['uri'] === '') {
                continue;
            }

            $titleLink = $article->querySelector('h2 > a');
            if ($titleLink === null) {
                continue;
            }
            $item['title'] = trim($titleLink->textContent ?? '');

            $timeEl = $article->querySelector('time');
            if ($timeEl !== null) {
                $datetime = $timeEl->getAttribute('datetime') ?? '';
                if ($datetime !== '') {
                    $parsed = strtotime($datetime);
                    if ($parsed !== false) {
                        $item['timestamp'] = $parsed;
                    }
                }
            }

            $authorEl = $article->querySelector('a.crayons-story__secondary.fw-medium');
            if ($authorEl !== null) {
                $item['author'] = trim($authorEl->textContent ?? '');
            }

            $profileImg = $article->querySelector('img');
            $imgSrc = '';
            if ($profileImg !== null) {
                $imgSrc = $profileImg->getAttribute('src') ?? '';
            }

            if ($this->getInput('full') === true) {
                $fullArticle = $this->getFullArticle($item['uri']);
                $item['content'] = '<p>' . $fullArticle . '</p>';
            } else {
                $escapedImg = htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8');
                $escapedAuthor = htmlspecialchars($item['author'] ?? '', ENT_QUOTES, 'UTF-8');
                $escapedTitle = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
                $item['content'] = '<img src="' . $escapedImg . '" alt="' . $escapedAuthor . '"><p>' . $escapedTitle . '</p>';
            }

            if ($this->getInput('hide_categories') !== true) {
                $tags = $article->querySelectorAll('a.crayons-tag');
                $categories = [];
                foreach ($tags as $tag) {
                    $tagText = trim($tag->textContent ?? '');
                    if ($tagText !== '') {
                        $categories[] = str_replace('#', '', $tagText);
                    }
                }
                if ($categories !== []) {
                    $item['categories'] = $categories;
                }
            }

            $this->items[] = $item;
        }
    }

    public function getName(): string
    {
        $tag = $this->getInput('tag');
        if ($tag !== null && $tag !== '') {
            return ucfirst((string)$tag) . ' - dev.to';
        }

        return parent::getName();
    }

    private function getFullArticle(string $url): string
    {
        $html = getContents($url);
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($dom->documentElement);

        $result = '';

        $cover = $dom->querySelector('div.crayons-article__cover');
        if ($cover !== null) {
            $result .= $dom->saveHTML($cover);
        }

        $articleBody = $dom->querySelector('[id="article-body"]');
        if ($articleBody !== null) {
            $result .= $dom->saveHTML($articleBody);
        }

        return $result;
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
}
