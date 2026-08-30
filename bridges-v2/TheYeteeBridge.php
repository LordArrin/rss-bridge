<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class TheYeteeBridge extends BridgeAbstract
{
    public const NAME = 'TheYetee';
    public const URI = 'https://theyetee.com';
    public const DESCRIPTION = 'Fetch daily shirts from The Yetee';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 14400;

    public function collectData(): void
    {
        $html = getContents(self::URI);

        if ($html === '') {
            throwServerException('Failed to fetch content');
        }

        $pattern = '/<script class="product-data" type="application\/json">(.*?)<\/script>/s';
        preg_match_all($pattern, $html, $matches);

        if (isset($matches[1]) === false || $matches[1] === []) {
            throwServerException('No products found');
        }

        foreach ($matches[1] as $jsonStr) {
            $data = json_decode($jsonStr, true);

            if (is_array($data) === false) {
                continue;
            }

            $title = 'Untitled';
            if (isset($data['title']) === true) {
                $title = (string) $data['title'];
            }

            $author = $this->extractAuthor($data);

            $handle = '';
            if (isset($data['handle']) === true) {
                $handle = (string) $data['handle'];
            }

            $uri = self::URI;
            if ($handle !== '') {
                $uri = 'https://theyetee.com/products/' . $handle;
            }

            $content = '';
            $images = $data['images'] ?? [];
            if (is_array($images) === true) {
                foreach ($images as $imageUrl) {
                    if (is_string($imageUrl) === true && $imageUrl !== '') {
                        $absoluteUrl = 'https:' . $imageUrl;
                        $content .= '<img src="' . htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') . '" /><br />';
                    }
                }
            }

            if ($content === '') {
                $content = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
            }

            $this->items[] = [
                'title' => $title,
                'author' => $author,
                'uri' => $uri,
                'content' => $content,
                'uid' => md5($title . $author . (string) time()),
            ];
        }

        if ($this->items === []) {
            throwServerException('No valid products found');
        }
    }

    private function extractAuthor(array $data): string
    {
        if (isset($data['vendor']) === true && is_string($data['vendor']) === true && $data['vendor'] !== '') {
            return $data['vendor'];
        }

        if (isset($data['artist']) === true && is_string($data['artist']) === true && $data['artist'] !== '') {
            return $data['artist'];
        }

        return 'Unknown';
    }
}
