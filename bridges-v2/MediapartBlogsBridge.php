<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class MediapartBlogsBridge extends BridgeAbstract
{
    public const NAME = 'Mediapart Blogs';
    public const URI = 'https://blogs.mediapart.fr';
    public const DESCRIPTION = 'Returns posts from a Mediapart blog';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'slug' => [
                'name' => 'Blog Slug',
                'type' => 'text',
                'title' => 'Blog user name',
                'required' => true,
                'exampleValue' => 'jean-vincot',
            ],
        ],
    ];

    public function getName(): string
    {
        $slugInput = $this->getInput('slug');

        if (is_string($slugInput) === true && $slugInput !== '') {
            return self::NAME . ' | ' . $slugInput;
        }

        return parent::getName();
    }

    public function collectData(): void
    {
        $slugInput = $this->getInput('slug');

        if (is_string($slugInput) === false || $slugInput === '') {
            throwClientException('Blog slug is required');
        }

        $slug = trim($slugInput);
        $url = self::URI . '/' . $slug . '/blog';

        $htmlString = getContents($url);

        if ($htmlString === '') {
            throwServerException('Failed to fetch blog page');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($htmlString);
        libxml_use_internal_errors(false);

        $posts = $dom->querySelectorAll('ul.post-list li');

        if (count($posts) === 0) {
            throwServerException('No posts found on the blog page');
        }

        foreach ($posts as $element) {
            $titleLink = $element->querySelector('h3.title a');
            if ($titleLink === null) {
                continue;
            }

            $title = trim($titleLink->innerHTML);
            $href = $titleLink->getAttribute('href');

            if ($href === null || $href === '') {
                continue;
            }

            $uri = self::URI . trim($href);

            $authorNode = $element->querySelector('.author .subscriber');
            $author = null;
            if ($authorNode !== null) {
                $authorText = trim($authorNode->innerHTML);
                if ($authorText !== '') {
                    $author = $authorText;
                }
            }

            $timeNode = $element->querySelector('.author time');
            $timestamp = time();
            if ($timeNode !== null) {
                $datetime = $timeNode->getAttribute('datetime');
                if ($datetime !== null && $datetime !== '') {
                    $parsed = strtotime($datetime);
                    if ($parsed !== false) {
                        $timestamp = $parsed;
                    }
                }
            }

            $divs = $element->querySelectorAll('div');
            $divCount = count($divs);

            $content = '';
            if ($divCount >= 2) {
                $secondLast = $divs[$divCount - 2]->outerHTML;
                $last = $divs[$divCount - 1]->outerHTML;
                $content = $secondLast . $last;
            }

            if ($content === '') {
                $content = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
            }

            $item = [
                'title' => $title,
                'uri' => $uri,
                'content' => $content,
                'timestamp' => $timestamp,
                'uid' => md5($uri),
            ];

            if ($author !== null) {
                $item['author'] = $author;
            }

            $this->items[] = $item;
        }

        if ($this->items === []) {
            throwServerException('No valid posts could be extracted');
        }
    }
}
