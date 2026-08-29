<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class NotAlwaysBridge extends BridgeAbstract
{
    public const NAME = 'Not Always family';
    public const URI = 'https://notalwaysright.com/';
    public const DESCRIPTION = 'Returns the latest stories';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            'filter' => [
                'type' => 'list',
                'name' => 'Filter',
                'values' => [
                    'All' => '',
                    'Right' => 'right',
                    'Working' => 'working',
                    'Romantic' => 'romantic',
                    'Related' => 'related',
                    'Learning' => 'learning',
                    'Hopeless' => 'hopeless',
                    'Healthy' => 'healthy',
                    'Legal' => 'legal',
                    'Friendly' => 'friendly',
                    'Unfiltered' => 'unfiltered'
                ]
            ]
        ]
    ];

    public function collectData(): void
    {
        $html = getContents($this->getURI());

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from NotAlways page');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $posts = $dom->querySelectorAll('.post');

        foreach ($posts as $post) {
            if ($post instanceof \Dom\Element === false) {
                continue;
            }

            $item = $this->parsePost($post);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }
    }

    private function parsePost(\Dom\Element $post): ?array
    {
        $h1 = $post->querySelector('h1');
        if ($h1 === null) {
            return null;
        }

        $link = $h1->querySelector('a');
        if ($link === null) {
            return null;
        }

        $href = (string) ($link->getAttribute('href') ?? '');
        if ($href === '') {
            return null;
        }

        $title = trim((string) $link->textContent);

        $postHeader = $post->querySelector('.post_header');
        $storyContent = $post->querySelector('.storycontent');

        $content = '';

        if ($postHeader !== null) {
            $content .= $postHeader->innerHTML;
        }

        $content .= '<br/><br/>';

        if ($storyContent !== null) {
            $this->limitImageSize($storyContent);
            $content .= $storyContent->innerHTML;
        }

        $item = [
            'uri' => $href,
            'title' => $title,
            'content' => $content,
            'uid' => md5($href),
        ];

        return $item;
    }

    private function limitImageSize(\Dom\Element $element): void
    {
        $images = $element->querySelectorAll('img');
        foreach ($images as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->setAttribute('style', self::CSS['img']);
            }
        }
    }

    public function getName(): string
    {
        $filterInput = $this->getInput('filter');
        if (is_string($filterInput) === true && $filterInput !== '') {
            return $filterInput . ' - NotAlways';
        }

        return parent::getName();
    }

    public function getURI(): string
    {
        $filterInput = $this->getInput('filter');
        if (is_string($filterInput) === true && $filterInput !== '') {
            return self::URI . $filterInput . '/';
        }

        return parent::getURI();
    }
}
