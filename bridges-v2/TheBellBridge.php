<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class TheBellBridge extends BridgeAbstract
{
    public const NAME = 'The Bell';
    public const URI = 'https://thebell.io';
    public const DESCRIPTION = 'Returns latest articles from news site The Bell';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const API_URL = 'https://thebell.io/api/v2/graphql';
    private const TIMESTAMP_DIVISOR = 1000;
    private const STORAGE_PATH = '/storage_v';

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    public const PARAMETERS = [[
        'category' => [
            'name' => 'Category',
            'type' => 'list',
            'title' => 'Category slug (news, morning-news, exclusive, etc)',
            'values' => [
                'Все' => null,
                'Новости' => 'news',
                'Утренняя рассылка' => 'morning-news',
                'Вечерняя рассылка' => 'evening-news',
                'Итоги недели' => 'itogi-nedeli',
                'Технорассылка' => 'rassylka-o-tehnologiyah',
                'Bell.Инвестиции' => 'bell-investitsii',
                'Истории' => 'istorii',
                'Эксклюзив' => 'exclusive',
                'Расследование' => 'rassledovanie',
                'The Bell объясняет' => 'the-bell-obyasnyaet',
                'Это Осетинская' => 'eto-osetinskaya',
            ],
        ],
        'limit' => self::LIMIT + [
            'defaultValue' => 20,
        ],
    ]];

    public const TEST_DETECT_PARAMETERS = [
        'https://thebell.io/category/exclusive' => ['category' => 'exclusive'],
        'https://thebell.io/' => [],
    ];

    public function getName()
    {
        $category = $this->getInput('category');
        if ($category !== null && $category !== '') {
            $labels = array_flip(self::PARAMETERS[0]['category']['values']);
            $label = $labels[$category] ?? $category;
            return self::NAME . ' - ' . $label;
        }

        return self::NAME;
    }

    public function collectData()
    {
        $limit = (int)$this->getInput('limit');
        $category = $this->getInput('category');

        $query = <<<'GQL'
query GetLatestArticles($first: Int!, $category: String) {
  published_posts(
    first: $first
    orderBy: "published_at"
    orderDirection: "DESC"
    category: $category
  ) {
    edges {
      node {
        id
        title
        subtitle
        slug
        published_at
        content
        authors {
          ... on Author {
            name_ru
            name_en
          }
        }
        categories {
          ... on Category {
            title
          }
        }
        tags {
          ... on Tag {
            title
          }
        }
      }
    }
  }
}
GQL;

        $variables = [
            'first' => $limit,
        ];

        if ($category !== null && $category !== '') {
            $variables['category'] = $category;
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode([
                'query' => $query,
                'variables' => $variables,
            ]),
        ];

        $response = getContents(
            self::API_URL,
            ['Content-Type: application/json'],
            $opts
        );

        $data = json_decode($response, true);
        if (is_array($data) === false) {
            throwServerException('Invalid JSON response from API');
        }

        $edges = $data['data']['published_posts']['edges'] ?? [];
        if (is_array($edges) === false) {
            return;
        }

        foreach ($edges as $edge) {
            if (is_array($edge) === false || isset($edge['node']) === false) {
                continue;
            }

            $node = $edge['node'];
            if (is_array($node) === false) {
                continue;
            }

            $id = $node['id'] ?? null;
            $title = $node['title'] ?? '';
            $slug = $node['slug'] ?? '';
            $publishedAt = $node['published_at'] ?? null;
            $content = $node['content'] ?? '';

            if ($id === null || $title === '' || $slug === '') {
                continue;
            }

            $authors = $this->extractAuthors($node['authors'] ?? []);
            $categories = $this->extractTitles($node['categories'] ?? []);
            $tags = $this->extractTitles($node['tags'] ?? []);

            $timestamp = null;
            if (is_numeric($publishedAt) === true) {
                $timestamp = (int)((int)$publishedAt / self::TIMESTAMP_DIVISOR);
            }

            $processedContent = $this->processContent($content);

            $this->items[] = [
                'uid' => (string)$id,
                'title' => $title,
                'uri' => self::URI . '/' . rawurlencode($slug),
                'timestamp' => $timestamp,
                'author' => $authors !== '' ? $authors : null,
                'content' => $processedContent,
                'categories' => array_merge($categories, $tags),
            ];
        }
    }

    public function detectParameters($url)
    {
        if (is_string($url) === false) {
            return null;
        }

        if (preg_match('/^https?:\/\/thebell\.io\/category\/([\w-]+)/i', $url, $m) === 1) {
            if (isset($m[1]) === true && $m[1] !== '') {
                return ['category' => $m[1]];
            }
        }

        if (preg_match('/^https?:\/\/thebell\.io(\/|$)/i', $url) === 1) {
            return [];
        }

        return null;
    }

    private function extractAuthors(array $authors): string
    {
        $names = [];

        foreach ($authors as $author) {
            if (is_array($author) === false) {
                continue;
            }

            $nameRu = $author['name_ru'] ?? '';
            $nameEn = $author['name_en'] ?? '';

            if ($nameRu !== '') {
                $names[] = $nameRu;
            } elseif ($nameEn !== '') {
                $names[] = $nameEn;
            }
        }

        return implode(', ', $names);
    }

    private function extractTitles(array $items): array
    {
        $titles = [];

        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $title = $item['title'] ?? '';
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    private function processContent(string $content): string
    {
        if ($content === '') {
            return '';
        }

        // Handle relative URLs in srcset
        $processed = str_replace(self::STORAGE_PATH, self::URI . self::STORAGE_PATH, $content);

        return $processed;
    }
}
