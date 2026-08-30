<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class ThreadsBridge extends BridgeAbstract
{
    public const NAME = 'Threads';
    public const URI = 'https://www.threads.net/';
    public const DESCRIPTION = 'Say more with Threads — Instagram\'s new text app.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'By username' => [
            'u' => [
                'name' => 'username',
                'required' => true,
                'exampleValue' => 'zuck',
                'title' => 'Insert a user name',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => false,
                'title' => 'Specify number of posts to fetch',
                'defaultValue' => 5,
            ],
        ],
    ];

    private const POST_CACHE_TIMEOUT = 15778800;
    private const THREAD_CODE_LENGTH = 11;
    private const USERNAME_REGEX = '/^(https?:\/\/)?(www\.)?threads\.net\/(@)?([^\/?\n]+)/';

    private ?string $feedName = null;

    public function getName()
    {
        if ($this->feedName !== null && $this->feedName !== '') {
            return $this->feedName;
        }

        return parent::getName();
    }

    public function detectParameters($url)
    {
        if (is_string($url) === false) {
            return null;
        }

        if (preg_match(self::USERNAME_REGEX, $url, $matches) === 1) {
            if (isset($matches[4]) === true && $matches[4] !== '') {
                return [
                    'context' => 'By username',
                    'u' => urldecode($matches[4]),
                ];
            }
        }

        return null;
    }

    public function getURI()
    {
        $username = $this->getInput('u');
        if ($username !== null && $username !== '') {
            return self::URI . '@' . (string)$username;
        }

        return parent::getURI();
    }

    public function collectData()
    {
        $html = getContents($this->getURI());

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $jsonBlobs = iterator_to_array($dom->querySelectorAll('script[type="application/json"]'));

        $gatheredPosts = [];
        $limit = (int)($this->getInput('limit') ?? 5);

        foreach ($jsonBlobs as $jsonBlob) {
            if ($jsonBlob instanceof \Dom\Element === false) {
                continue;
            }

            $jsonText = $jsonBlob->textContent ?? '';
            if ($jsonText === '') {
                continue;
            }

            $jsonData = json_decode($jsonText, true);
            if ($jsonData === null) {
                continue;
            }

            $posts = $this->recursiveFind($jsonData, 'post');

            foreach ($posts as $post) {
                if (is_array($post) === false) {
                    continue;
                }

                if (isset($post['code']) === false) {
                    continue;
                }

                $candidateCode = (string)$post['code'];

                if (strlen($candidateCode) !== self::THREAD_CODE_LENGTH) {
                    continue;
                }

                if (isset($gatheredPosts[$candidateCode]) === true) {
                    continue;
                }

                $gatheredPosts[$candidateCode] = [
                    'code' => $candidateCode,
                    'taken_at' => $post['taken_at'] ?? null,
                ];

                if (count($gatheredPosts) >= $limit) {
                    break 2;
                }
            }
        }

        $ogTitle = $dom->querySelector('meta[property="og:title"]');
        if ($ogTitle !== null) {
            $titleContent = $ogTitle->getAttribute('content') ?? '';
            if ($titleContent !== '') {
                $this->feedName = html_entity_decode($titleContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        foreach ($gatheredPosts as $postData) {
            $code = $postData['code'];
            $postUrl = $this->getURI() . '/post/' . $code;

            $articleHtml = getContents($postUrl);

            libxml_use_internal_errors(true);
            $articleDom = \Dom\HTMLDocument::createFromString($articleHtml);
            libxml_use_internal_errors(false);

            $ogType = $articleDom->querySelector('meta[property="og:type"]');
            if ($ogType === null) {
                continue;
            }

            $typeContent = $ogType->getAttribute('content') ?? '';
            if ($typeContent !== 'article') {
                continue;
            }

            $ogDescription = $articleDom->querySelector('meta[property="og:description"]');
            if ($ogDescription === null) {
                continue;
            }

            $description = $ogDescription->getAttribute('content') ?? '';
            if ($description === '') {
                continue;
            }

            $ogTitle = $articleDom->querySelector('meta[property="og:title"]');
            $author = '';
            if ($ogTitle !== null) {
                $author = html_entity_decode(
                    $ogTitle->getAttribute('content') ?? '',
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            }

            $item = [];
            $item['uri'] = $postUrl;
            $item['title'] = $description;
            $item['content'] = e($description);
            $item['author'] = $author !== '' ? $author : null;

            $ogImage = $articleDom->querySelector('meta[property="og:image"]');
            if ($ogImage !== null) {
                $imageUrl = $ogImage->getAttribute('content') ?? '';
                if ($imageUrl !== '') {
                    $item['enclosures'] = [
                        html_entity_decode($imageUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ];
                }
            }

            if (isset($postData['taken_at']) === true && $postData['taken_at'] !== null) {
                $item['timestamp'] = (int)$postData['taken_at'];
            }

            $this->items[] = $item;
        }
    }

    private function recursiveFind(mixed $haystack, string $needle): array
    {
        $found = [];

        if (is_array($haystack) === false && is_object($haystack) === false) {
            return $found;
        }

        $haystackArray = $haystack;
        if (is_object($haystack) === true) {
            $haystackArray = (array) $haystack;
        }

        $iterator = new \RecursiveArrayIterator(
            $haystackArray,
            \RecursiveArrayIterator::CHILD_ARRAYS_ONLY
        );
        $recursive = new \RecursiveIteratorIterator(
            $iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($recursive as $key => $value) {
            if ($key === $needle) {
                $found[] = $value;
            }
        }

        return $found;
    }
}
