<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class ItchBridge extends BridgeAbstract
{
    public const NAME = 'Itch.io Devlog';
    public const URI = 'https://itch.io/';
    public const DESCRIPTION = 'Returns devlog posts from Itch.io developers or games. Supports fetching full article content and private devlogs via authentication token.';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public const CONFIGURATION = [
        'token' => [
            'required' => false,
        ],
    ];

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 1600px; width: auto; height: auto; margin: 16px 0 16px 0; padding: 0;',
        'text' => 'text-align: left;',
    ];

    public const PARAMETERS = [
        [
            'url' => [
                'name' => 'Game URL',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'https://sad-crab.itch.io/innocent-witches',
                'title' => 'Full game URL - e.g. https://sad-crab.itch.io/innocent-witches',
            ],
            'token' => [
                'name' => 'Itch.io Token',
                'type' => 'text',
                'required' => false,
                'title' => 'Optional. The value of the "itchio" cookie from your browser. Required for private devlogs and bypassing 18+ gates. Leave empty to use server default',
            ],
            'fetch_full' => [
                'name' => 'Fetch full content',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'If checked, the bridge will fetch and parse the full article page for each post instead of using the short RSS preview.',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum number of items to return.',
            ]
        ]
    ];

    private function resolveToken(): string
    {
        $input = $this->getInput('token');
        if ($input !== null && $input !== '') {
            return (string) $input;
        }

        $option = $this->getOption('token');
        if ($option !== null && $option !== '') {
            return (string) $option;
        }

        return '';
    }

    private function processYouTubeIframes(string $html): string
    {
        return preg_replace_callback(
            '/<iframe[^>]*src=["\'](?:https?:)?\/\/(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]+)[^"\']*["\'][^>]*>/i',
            function ($matches) {
                $videoId = $matches[1];
                $thumbnailUrl = "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg";
                $fallbackUrl = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                $videoUrl = "https://www.youtube.com/watch?v={$videoId}";

                $thumbnailData = @file_get_contents($thumbnailUrl);
                if ($thumbnailData === false || strlen($thumbnailData) < 100) {
                    $thumbnailUrl = $fallbackUrl;
                }

                $linkStyle = 'display: block; text-align: center; margin: 0 0 16px 0;';

                return sprintf(
                    '<p><a href="%s" target="_blank"><img src="%s" style="%s" alt="YouTube Video" /></a><a href="%s" target="_blank" style="%s">Watch on YouTube</a></p>',
                    htmlspecialchars($videoUrl, ENT_QUOTES),
                    htmlspecialchars($thumbnailUrl, ENT_QUOTES),
                    self::CSS['img'],
                    htmlspecialchars($videoUrl, ENT_QUOTES),
                    $linkStyle
                );
            },
            $html
        );
    }

    private function processImages(string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
        libxml_clear_errors();

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return $html;
        }

        $this->limitImageSize($wrapper);
        $this->alignTextLeft($wrapper);

        return (string)$wrapper->innerHTML;
    }

    private function limitImageSize(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::CSS['img']);
            }
        }
    }

    private function alignTextLeft(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('p, div, h1, h2, h3, h4, h5, h6') as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $style = (string)($element->getAttribute('style') ?? '');
            if ($style !== '') {
                $newStyle = preg_replace('/text-align\s*:\s*center\s*;?/i', '', $style);
                if (is_string($newStyle) === true && $newStyle !== $style) {
                    $newStyle = trim($newStyle);
                    if ($newStyle === '') {
                        $element->removeAttribute('style');
                    } else {
                        $element->setAttribute('style', $newStyle);
                    }
                }
            }

            $element->setAttribute('align', 'left');
        }
    }

    public function collectData(): void
    {
        $url = (string) $this->getInput('url');
        $limit = (int) ($this->getInput('limit') ?? 10);
        $fetchFull = (bool) $this->getInput('fetch_full');
        $token = $this->resolveToken();

        if (preg_match('/^https?:\/\/([a-z0-9-]+)\.itch\.io(?:\/([a-z0-9-]+))?/', $url, $matches) === 0) {
            throw new \ClientException('Invalid Itch.io URL. Please provide a valid developer or game URL.');
        }

        $developer = $matches[1];
        $game = $matches[2] ?? null;

        $rssUrl = $game !== null ? "https://{$developer}.itch.io/{$game}/devlog.rss" : "https://{$developer}.itch.io/devlog.rss";

        $headers = [];
        if ($token !== '') {
            $headers = [
                'Cookie: itchio=' . $token . '; itchio_age=1; itchio_key=' . $token,
            ];
        }

        $xmlString = getContents($rssUrl, $headers);
        if ($xmlString === '') {
            throw new \Exception('Failed to fetch RSS feed from Itch.io. The feed might be empty or the developer/game does not exist.');
        }

        libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($rss === false || isset($rss->channel->item) === false) {
            throw new \Exception('Failed to parse Itch.io RSS feed.');
        }

        $count = 0;
        foreach ($rss->channel->item as $item) {
            if ($count >= $limit) {
                break;
            }

            $title = (string)$item->title;
            $link = (string)$item->link;
            $description = (string)$item->description;
            $pubDate = strtotime((string)$item->pubDate);

            $content = $description;

            if ($fetchFull === true && $link !== '') {
                $dom = getSimpleHTMLDOM($link, $headers);

                $postBody = $dom->querySelector('.post_body.user_formatted.base_widget.object_text_widget_widget');
                if ($postBody === null) {
                    $postBody = $dom->querySelector('.post_body.user_formatted');
                }
                if ($postBody === null) {
                    $postBody = $dom->querySelector('.post_body');
                }
                if ($postBody === null) {
                    $postBody = $dom->querySelector('#post_body');
                }
                if ($postBody === null) {
                    $postBody = $dom->querySelector('article .body');
                }
                if ($postBody === null) {
                    $postBody = $dom->querySelector('main .post_content');
                }
                if ($postBody === null) {
                    $postBody = $dom->querySelector('section.body');
                }

                if ($postBody !== null) {
                    $content = $postBody->innerHTML;
                }
            }

            $content = $this->processYouTubeIframes($content);
            $content = $this->processImages($content);

            $this->items[] = [
                'title' => $title,
                'uri' => $link,
                'content' => $content,
                'timestamp' => $pubDate ?? time(),
                'uid' => hash('sha256', $link),
            ];

            $count++;
        }
    }
}
