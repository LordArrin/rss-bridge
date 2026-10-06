<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class WebfailBridge extends BridgeAbstract
{
    public const NAME = 'Webfail';
    public const URI = 'https://webfail.com';
    public const DESCRIPTION = 'Returns the latest fails';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'By content type' => [
            'language' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => [
                    'English' => 'en',
                    'German' => 'de'
                ],
                'defaultValue' => 'English'
            ],
            'type' => [
                'name' => 'Type',
                'type' => 'list',
                'title' => 'Select your content type',
                'values' => [
                    'None' => '/',
                    'Facebook' => '/ffdts',
                    'Images' => '/images',
                    'Videos' => '/videos',
                    'Gifs' => '/gifs'
                ],
                'defaultValue' => 'None'
            ]
        ]
    ];

    public function getURI(): string
    {
        $language = $this->getInput('language');
        if ($language === null) {
            return parent::getURI();
        }
        return 'https://' . (string)$language . '.webfail.com';
    }

    public function collectData(): void
    {
        $html = getSimpleHTMLDOM($this->getURI() . $this->getInput('type'));

        $type = (string)$this->getKey('type');

        switch (strtolower($type)) {
            case 'facebook':
            case 'videos':
                $this->extractNews($html, $type);
                break;
            case 'none':
            case 'images':
            case 'gifs':
                $this->extractArticle($html);
                break;
            default:
                throwClientException('Unknown type: ' . $type);
        }
    }

    private function extractNews(\Dom\HTMLDocument $html, string $type): void
    {
        $main = $html->querySelector('#main');
        if ($main === null) {
            return;
        }

        $news = $main->querySelectorAll('a.wf-list-news');

        foreach ($news as $element) {
            usleep(500000);

            $item = [];

            $titleElement = $element->querySelector('div.wf-news-title');
            $item['title'] = $this->fixTitle($titleElement !== null ? (string)$titleElement->innerHTML : '');
            $item['uri'] = $this->getURI() . (string)$element->getAttribute('href');

            $imgElement = $element->querySelector('img.wf-image');
            $img = $imgElement !== null ? (string)$imgElement->getAttribute('src') : '';

            if (strtolower($type) === 'facebook' && $img !== '') {
                $img = $this->getImageHiResUri($item['uri']);
            }

            $description = '';
            $descriptionElement = $element->querySelector('div.wf-news-description');
            if ($descriptionElement !== null) {
                $description = (string)$descriptionElement->innerHTML;
            }

            $infoElement = $element->querySelector('div.wf-small');
            if ($infoElement !== null) {
                $infoText = (string)$infoElement->textContent;
                if (preg_match('/(\d{2}\.\d{2}\.\d{4})/m', $infoText, $matches) === 1 && count($matches) === 2) {
                    $dt = \DateTime::createFromFormat('!d.m.Y', $matches[1]);
                    if ($dt !== false) {
                        $item['timestamp'] = $dt->getTimestamp();
                    }
                }
            }

            $item['content'] = '<p>'
                . $description
                . '</p><br><a href="'
                . htmlspecialchars($item['uri'], ENT_QUOTES, 'UTF-8')
                . '"><img src="'
                . htmlspecialchars($img, ENT_QUOTES, 'UTF-8')
                . '"></a>';

            $this->items[] = $item;
        }
    }

    private function extractArticle(\Dom\HTMLDocument $html): void
    {
        $articles = $html->querySelectorAll('article');

        foreach ($articles as $article) {
            usleep(500000);

            $item = [];

            $links = $article->querySelectorAll('a');
            if (count($links) < 2) {
                continue;
            }

            $titleLink = $links[1];
            $item['title'] = $this->fixTitle((string)$titleLink->innerHTML);

            $imgElement = $article->querySelector('img.wf-image');
            if ($imgElement !== null) {
                if (count($links) >= 3) {
                    $item['uri'] = $this->getURI() . (string)$links[2]->getAttribute('href');
                    $item['content'] = '<a href="'
                        . htmlspecialchars($item['uri'], ENT_QUOTES, 'UTF-8')
                        . '"><img src="'
                        . htmlspecialchars((string)$imgElement->getAttribute('src'), ENT_QUOTES, 'UTF-8')
                        . '"></a>';
                    $this->items[] = $item;
                }
            } else {
                $videoElement = $article->querySelector('div.wf-video');
                if ($videoElement !== null) {
                    $playElement = $article->querySelector('div.wf-play');
                    if ($playElement !== null) {
                        $onclick = (string)$playElement->getAttribute('onclick');
                        $videoId = $this->getVideoId($onclick);
                        if ($videoId !== '') {
                            $item['uri'] = 'https://www.youtube.com/watch?v=' . $videoId;
                            if (function_exists('handleYoutube') === true) {
                                $item['content'] = handleYoutube($videoId);
                            } else {
                                $item['content'] = '<a href="' . htmlspecialchars($item['uri'], ENT_QUOTES, 'UTF-8') . '">Watch on YouTube</a>';
                            }
                            $this->items[] = $item;
                        }
                    }
                } else {
                    $gifElement = $article->querySelector('video[id*=gif-]');
                    if ($gifElement !== null) {
                        if (count($links) >= 3) {
                            $item['uri'] = $this->getURI() . (string)$links[2]->getAttribute('href');
                            $src = (string)$gifElement->getAttribute('src');
                            $poster = (string)$gifElement->getAttribute('poster');
                            $item['content'] = '<video controls src="'
                                . htmlspecialchars($src, ENT_QUOTES, 'UTF-8')
                                . '" poster="'
                                . htmlspecialchars($poster, ENT_QUOTES, 'UTF-8')
                                . '"></video>';
                            $this->items[] = $item;
                        }
                    }
                }
            }
        }
    }

    private function fixTitle(string $title): string
    {
        return html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function getVideoId(string $onclick): string
    {
        $length = strlen($onclick);
        if ($length < 32) {
            return '';
        }
        $id = substr($onclick, 21, 11);
        if ($id === false) {
            return '';
        }
        return $id;
    }

    private function getImageHiResUri(string $url): string
    {
        $lastSlashPos = strrpos($url, '/');
        $lastQuestionPos = strrpos($url, '?');

        if ($lastSlashPos === false || $lastQuestionPos === false || $lastQuestionPos <= $lastSlashPos) {
            return '';
        }

        $id = substr($url, $lastSlashPos + 1, $lastQuestionPos - $lastSlashPos - 1);
        if ($id === false || $id === '') {
            return '';
        }

        return 'http://cdn.webfail.com/upl/img/' . $id . '/post2.jpg';
    }
}
