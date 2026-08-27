<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class EuronewsBridge extends BridgeAbstract
{
    public const NAME = 'Euronews';
    public const URI = 'https://www.euronews.com/';
    public const CACHE_TIMEOUT = 600;
    public const DESCRIPTION = 'Return articles from the "Just In" feed of Euronews.';
    public const MAINTAINER = 'No maintainer';

    public const PARAMETERS = [
        '' => [
            'lang' => [
                'name' => 'Language',
                'type' => 'list',
                'defaultValue' => 'www.euronews.com',
                'values' => [
                    'English' => 'www.euronews.com',
                    'French' => 'fr.euronews.com',
                    'German' => 'de.euronews.com',
                    'Italian' => 'it.euronews.com',
                    'Spanish' => 'es.euronews.com',
                    'Portuguese' => 'pt.euronews.com',
                    'Russian' => 'ru.euronews.com',
                    'Turkish' => 'tr.euronews.com',
                    'Greek' => 'gr.euronews.com',
                    'Hungarian' => 'hu.euronews.com',
                    'Persian' => 'per.euronews.com',
                    'Arabic' => 'arabic.euronews.com',
                ],
            ],
            'limit' => [
                'name' => 'Limit of items per feed',
                'required' => true,
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum number of returned feed items. Maximum 50, default 10',
            ],
        ],
    ];

    public function collectData()
    {
        $limit = (int)($this->getInput('limit') ?? 10);
        $lang = $this->getInput('lang');

        if (is_string($lang) === false) {
            $lang = 'www.euronews.com';
        }

        if ($lang === 'euronews.com') {
            $lang = 'www.euronews.com';
        }

        $rootUrl = 'https://' . $lang;
        $url = $rootUrl . '/api/timeline.json?limit=' . $limit;
        $json = getContents($url);
        $data = json_decode($json, true);

        if (is_array($data) === false) {
            return;
        }

        foreach ($data as $datum) {
            if (is_array($datum) === false) {
                continue;
            }

            $datumUri = $rootUrl . ($datum['path'] ?? '');
            $urlDatum = $this->getItemContent($datumUri);
            $categories = [];

            if (isset($datum['program']['title']) === true) {
                $categories[] = (string)$datum['program']['title'];
            }

            if (isset($datum['themes']) === true && is_array($datum['themes']) === true) {
                foreach ($datum['themes'] as $theme) {
                    if (isset($theme['title']) === true) {
                        $categories[] = (string)$theme['title'];
                    }
                }
            }

            $item = [
                'uri' => $datumUri,
                'title' => (string)($datum['title'] ?? ''),
                'uid' => (string)($datum['id'] ?? ''),
                'timestamp' => $datum['publishedAt'] ?? null,
                'content' => $urlDatum['content'] ?? '',
                'author' => $urlDatum['author'] ?? null,
                'enclosures' => $urlDatum['enclosures'] ?? [],
                'categories' => array_unique($categories),
            ];

            $this->items[] = $item;
        }
    }

    private function getItemContent(string $url): array
    {
        try {
            $html = getContents($url);
        } catch (\Exception $e) {
            return ['author' => null, 'content' => '', 'enclosures' => []];
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $author = 'Euronews';
        $content = '';
        $enclosures = [];

        $jsonLdScripts = $dom->querySelectorAll('script[type="application/ld+json"]');
        foreach ($jsonLdScripts as $jsonLdScript) {
            $jsonData = json_decode($jsonLdScript->textContent ?? '', true);

            if (is_array($jsonData) === false) {
                continue;
            }

            if (isset($jsonData['@graph']) === true && is_array($jsonData['@graph']) === true) {
                foreach ($jsonData['@graph'] as $graphItem) {
                    if (is_array($graphItem) === false) {
                        continue;
                    }

                    $itemType = $graphItem['@type'] ?? '';
                    if ($itemType !== 'NewsArticle') {
                        continue;
                    }

                    if (isset($graphItem['author']) === true) {
                        if (is_array($graphItem['author']) === true) {
                            $authorNames = [];
                            foreach ($graphItem['author'] as $authorEntry) {
                                if (is_array($authorEntry) === true && isset($authorEntry['name']) === true) {
                                    $authorNames[] = (string)$authorEntry['name'];
                                }
                            }
                            if (count($authorNames) > 0) {
                                $author = implode(', ', $authorNames);
                            }
                        } elseif (isset($graphItem['author']['name']) === true) {
                            $author = (string)$graphItem['author']['name'];
                        }
                    }

                    if (isset($graphItem['image']) === true) {
                        $imageUrl = $graphItem['image']['url'] ?? '';
                        $imageCaption = $graphItem['image']['caption'] ?? '';
                        if ($imageUrl !== '') {
                            $content .= '<figure>';
                            $content .= '<img src="' . e($imageUrl) . '">';
                            if ($imageCaption !== '') {
                                $content .= '<figcaption>' . e($imageCaption) . '</figcaption>';
                            }
                            $content .= '</figure><br>';
                        }
                    }

                    if (isset($graphItem['video']['contentUrl']) === true) {
                        $enclosures[] = (string)$graphItem['video']['contentUrl'];
                    }
                }
            }
        }

        // Добавляем summary (подзаголовок)
        $summary = $dom->querySelector('.c-article-summary');
        if ($summary !== null) {
            $summaryText = $summary->textContent ?? '';
            if ($summaryText !== '') {
                $content .= '<p><em>' . e($summaryText) . '</em></p>';
            }
        }

        // Основной контент статьи - пробуем несколько вариантов селекторов
        $articleContent = $dom->querySelector('.js-article-content.c-article-content');

        if ($articleContent === null) {
            $articleContent = $dom->querySelector('.js-article-content');
        }

        if ($articleContent === null) {
            $articleContent = $dom->querySelector('.c-article-content');
        }

        if ($articleContent === null) {
            $articleContent = $dom->querySelector('[class*="article-content"]');
        }

        if ($articleContent !== null) {
            // Извлекаем весь innerHTML элемента как есть
            $articleHtml = $articleContent->innerHTML ?? '';
            if ($articleHtml !== '') {
                $content .= $articleHtml;
            }
        }

        // Fallback для видео-статей
        if ($articleContent === null) {
            $image = $dom->querySelector('.c-article-media__img');
            if ($image !== null) {
                $imgSrc = $image->getAttribute('src') ?? '';
                if ($imgSrc !== '') {
                    $content .= '<figure>';
                    $content .= '<img src="' . e($imgSrc) . '">';
                    $content .= '</figure><br>';
                }
            }

            $description = $dom->querySelector('.m-object__description');
            if ($description !== null) {
                $descText = $description->textContent ?? '';
                if ($descText !== '') {
                    $content .= '<div>' . e($descText) . '</div>';
                }
            }

            $playerDiv = $dom->querySelector('.dmPlayer');
            if ($playerDiv !== null) {
                $videoId = $playerDiv->getAttribute('data-video-id') ?? '';
                if ($videoId !== '') {
                    $videoUrl = 'https://www.dailymotion.com/video/' . $videoId;
                    $content .= '<a href="' . e($videoUrl) . '">' . e($videoUrl) . '</a>';
                }
            }

            $playerDiv = $dom->querySelector('.js-player-pfp');
            if ($playerDiv !== null) {
                $videoId = $playerDiv->getAttribute('data-video-id') ?? '';
                if ($videoId !== '') {
                    $content .= handleYoutube($videoId);
                }
            }
        }

        return [
            'author' => $author,
            'content' => $content,
            'enclosures' => $enclosures,
        ];
    }
}
