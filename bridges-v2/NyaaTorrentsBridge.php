<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\FeedParser;

final class NyaaTorrentsBridge extends BridgeAbstract
{
    public const NAME = 'NyaaTorrents';
    public const URI = 'https://nyaa.si/';
    public const DESCRIPTION = 'Returns the newest torrents, with optional search criteria';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 600;

    private const CSS = [
        'image' => 'max-width: 600px; width: auto; height: auto;',
        'buttons_wrap' => 'margin: 12px 0; font-family: Arial, sans-serif;',
        'button' => 'display: inline-block; margin-right: 8px; padding: 6px 14px; border-radius: 4px; color: #ffffff; text-decoration: none; font-size: 13px; font-weight: bold;',
        'button_torrent' => 'background-color: #3585b8;',
        'button_magnet' => 'background-color: #7b57c4;',
    ];

    public const PARAMETERS = [
        [
            'f' => [
                'name' => 'Filter',
                'type' => 'list',
                'values' => [
                    'No filter' => '0',
                    'No remakes' => '1',
                    'Trusted only' => '2'
                ]
            ],
            'c' => [
                'name' => 'Category',
                'type' => 'list',
                'values' => [
                    'All categories' => '0_0',
                    'Anime' => '1_0',
                    'Anime - AMV' => '1_1',
                    'Anime - English' => '1_2',
                    'Anime - Non-English' => '1_3',
                    'Anime - Raw' => '1_4',
                    'Audio' => '2_0',
                    'Audio - Lossless' => '2_1',
                    'Audio - Lossy' => '2_2',
                    'Literature' => '3_0',
                    'Literature - English' => '3_1',
                    'Literature - Non-English' => '3_2',
                    'Literature - Raw' => '3_3',
                    'Live Action' => '4_0',
                    'Live Action - English' => '4_1',
                    'Live Action - Idol/PV' => '4_2',
                    'Live Action - Non-English' => '4_3',
                    'Live Action - Raw' => '4_4',
                    'Pictures' => '5_0',
                    'Pictures - Graphics' => '5_1',
                    'Pictures - Photos' => '5_2',
                    'Software' => '6_0',
                    'Software - Apps' => '6_1',
                    'Software - Games' => '6_2',
                ]
            ],
            'q' => [
                'name' => 'Keyword',
                'description' => 'Keyword(s)',
                'type' => 'text'
            ],
            'u' => [
                'name' => 'User',
                'description' => 'User',
                'type' => 'text'
            ]
        ]
    ];

    public function collectData(): void
    {
        $feedParser = new FeedParser();
        $feed = $feedParser->parseFeed(getContents($this->getURI()));

        if (isset($feed['items']) === false || is_array($feed['items']) === false) {
            \throwServerException('Invalid feed format');
        }

        $parsedown = new \Parsedown();

        foreach ($feed['items'] as $feedItem) {
            if (is_array($feedItem) === false) {
                continue;
            }

            $item = $feedItem;
            $originalUri = (string) ($item['uri'] ?? '');

            $viewUri = str_replace('.torrent', '', $originalUri);
            $viewUri = str_replace('/download/', '/view/', $viewUri);
            $item['uri'] = $viewUri;

            $item['uid'] = str_replace('https://nyaa.si/view/', '', $viewUri);

            $content = '';
            $magnetUrl = '';

            if ($viewUri !== '') {
                $html = getContents($viewUri);
                if (is_string($html) === true && $html !== '') {
                    $dom = \Dom\HTMLDocument::createFromString($html);

                    $descriptionNode = $dom->querySelector('#torrent-description');
                    $description = $descriptionNode?->innerHTML ?? '';

                    $decodedDescription = html_entity_decode((string) $description);
                    if (function_exists('markdownToHtml') === true) {
                        $content = \markdownToHtml($decodedDescription);
                    } else {
                        $content = $parsedown->text($decodedDescription);
                    }

                    $links = $dom->querySelectorAll('div.panel-footer.clearfix > a');
                    if ($links->length > 1) {
                        $magnetHref = $links->item(1)?->getAttribute('href') ?? '';
                        if (is_string($magnetHref) === true && $magnetHref !== '') {
                            $decodedMagnet = html_entity_decode($magnetHref);
                            if (str_contains($decodedMagnet, 'https://torrent.parts/#') === true) {
                                $decodedMagnet = str_replace('https://torrent.parts/#', '', $decodedMagnet);
                            }
                            $magnetUrl = $decodedMagnet;
                        }
                    }
                }
            }

            $item['content'] = $this->limitImageSize($content) . $this->buildButtons($originalUri, $magnetUrl);

            $this->items[] = $item;

            if (count($this->items) >= 10) {
                break;
            }
        }
    }

    private function limitImageSize(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $dom = \Dom\HTMLDocument::createFromString('<div id="rss-bridge-content">' . $content . '</div>');
        $wrapper = $dom->querySelector('#rss-bridge-content');

        if ($wrapper === null) {
            return $content;
        }

        foreach ($wrapper->querySelectorAll('img') as $img) {
            $img->setAttribute('style', self::CSS['image']);
        }

        return $wrapper->innerHTML;
    }

    private function buildButtons(string $torrentUrl, string $magnetUrl): string
    {
        if ($torrentUrl === '' && $magnetUrl === '') {
            return '';
        }

        $html = '<div style="' . self::CSS['buttons_wrap'] . '">';

        if ($torrentUrl !== '') {
            $html .= '<a href="' . htmlspecialchars($torrentUrl) . '" style="' . self::CSS['button'] . self::CSS['button_torrent'] . '">Torrent</a>';
        }

        if ($magnetUrl !== '') {
            $html .= '<a href="' . htmlspecialchars($magnetUrl) . '" style="' . self::CSS['button'] . self::CSS['button_magnet'] . '">Magnet</a>';
        }

        $html .= '</div>';

        return $html;
    }

    public function getName(): string
    {
        $name = parent::getName();

        $userInput = $this->getInput('u');
        if (is_string($userInput) === true && $userInput !== '') {
            $name .= ' - ' . $userInput;
        }

        $keywordInput = $this->getInput('q');
        if (is_string($keywordInput) === true && $keywordInput !== '') {
            $name .= ' - ' . $keywordInput;
        }

        $categoryKey = $this->getKey('c');
        if (is_string($categoryKey) === true && $categoryKey !== '') {
            $name .= ' (' . $categoryKey . ')';
        }

        return $name;
    }

    public function getIcon(): string
    {
        return self::URI . 'static/favicon.png';
    }

    public function getURI(): string
    {
        $params = [
            'f' => (string) ($this->getInput('f') ?? ''),
            'c' => (string) ($this->getInput('c') ?? ''),
            'q' => (string) ($this->getInput('q') ?? ''),
            'u' => (string) ($this->getInput('u') ?? ''),
        ];
        $queryString = http_build_query($params);
        if (is_string($queryString) === false) {
            $queryString = '';
        }
        return self::URI . '?page=rss&s=id&o=desc&' . $queryString;
    }
}
