<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class WarhammerComBridge extends BridgeAbstract
{
    public const NAME = 'Warhammer Community Blog';
    public const URI = 'https://www.warhammer-community.com';
    public const DESCRIPTION = 'Warhammer Community Blog';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 86400;

    public const SELECTORS_TO_REMOVE = [
        '.copy-bitter-xs',
        '.border.rounded-full',
        '.overflow-y-scroll.wysiwyg',
    ];

    public const TABLE_CSS = <<<'CSS'
table.wc-table {
    border-collapse: collapse;
    width: 100%;
    margin: 10px 0;
}
table.wc-table td,
table.wc-table th {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
}
table.wc-table tr:first-child {
    font-weight: bold;
}
CSS;

    public function collectData(): void
    {
        $url = self::URI . '/api/search/news/';

        $headers = [
            'Content-Type: application/json',
        ];

        $data = '{"sortBy":"date_desc","category":"","collections":["articles"],"game_systems":[],"index":"news","locale":"en-gb","page":0,"perPage":16,"topics":[]}';

        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
        ];

        $jsonString = getContents($url, $headers, $opts);
        $json = json_decode($jsonString, false);

        if (is_object($json) === false || isset($json->news) === false || is_array($json->news) === false) {
            throwServerException('Failed to fetch or parse news data');
        }

        foreach ($json->news as $article) {
            if (is_object($article) === false || isset($article->uri) === false) {
                continue;
            }

            $articleUrl = self::URI . $article->uri;
            $fullArticleHtml = getContents($articleUrl);

            if ($fullArticleHtml === '') {
                continue;
            }

            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString($fullArticleHtml);
            libxml_use_internal_errors(false);

            $contentNode = $dom->querySelector('.article-content');
            if ($contentNode === null) {
                continue;
            }

            $this->removeUnwantedElements($contentNode);
            $this->addClassToTables($contentNode);

            $styleTag = '<style>' . self::TABLE_CSS . '</style>';
            $content = $styleTag . $contentNode->innerHTML;

            $categories = [];
            if (isset($article->topics) === true && is_array($article->topics) === true) {
                foreach ($article->topics as $topic) {
                    if (is_object($topic) === true && isset($topic->title) === true) {
                        $categories[] = $topic->title;
                    }
                }
            }

            $timestamp = time();
            if (isset($article->date) === true && is_string($article->date) === true) {
                $parsed = strtotime($article->date);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }

            $title = 'Untitled';
            if (isset($article->title) === true && is_string($article->title) === true) {
                $title = $article->title;
            }

            $uid = md5($articleUrl);
            if (isset($article->uuid) === true && is_string($article->uuid) === true) {
                $uid = $article->uuid;
            }

            $this->items[] = [
                'title' => $title,
                'uri' => $articleUrl,
                'timestamp' => $timestamp,
                'content' => $content,
                'uid' => $uid,
                'categories' => $categories,
            ];
        }

        if ($this->items === []) {
            throwServerException('No articles found');
        }
    }

    private function removeUnwantedElements(\Dom\Element $root): void
    {
        foreach (self::SELECTORS_TO_REMOVE as $selector) {
            $elements = $root->querySelectorAll($selector);
            foreach ($elements as $element) {
                $element->remove();
            }
        }
    }

    private function addClassToTables(\Dom\Element $root): void
    {
        $tables = $root->querySelectorAll('table');
        foreach ($tables as $table) {
            $existingClass = $table->getAttribute('class') ?? '';
            $newClass = trim($existingClass . ' wc-table');
            $table->setAttribute('class', $newClass);
        }
    }
}
