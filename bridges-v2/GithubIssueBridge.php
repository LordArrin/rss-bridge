<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class GithubIssueBridge extends BridgeAbstract
{
    public const NAME = 'GitHub Issues';
    public const URI = 'https://github.com/';
    public const API_URI = 'https://api.github.com/';
    public const DESCRIPTION = 'Returns the issues of a GitHub project';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 600;

    public const CONFIGURATION = [
        'token' => [
            'required' => false,
        ],
    ];

    public const PARAMETERS = [
        [
            'owner' => [
                'name' => 'Owner',
                'exampleValue' => 'RSS-Bridge',
                'required' => true,
            ],
            'repo' => [
                'name' => 'Repository',
                'exampleValue' => 'rss-bridge',
                'required' => true,
            ],
            'q' => [
                'name' => 'Search Query',
                'defaultValue' => 'is:issue is:open sort:updated-desc',
                'required' => true,
            ],
            'e' => [
                'name' => 'Show Events',
                'type' => 'checkbox',
            ],
            'limit' => [
                'name' => 'Posts limit',
                'type' => 'number',
                'defaultValue' => 10,
            ],
        ],
    ];

    private const SEARCH_TYPE_QUALIFIER = 'is:issue';

    private function resolveToken(): string
    {
        $option = $this->getOption('token');
        if ($option !== null && $option !== '') {
            return (string) $option;
        }

        return '';
    }

    public function getName(): string
    {
        $owner = (string) $this->getInput('owner');
        $repo = (string) $this->getInput('repo');

        if ($owner !== '' && $repo !== '') {
            return self::NAME . 's for ' . $owner . '/' . $repo;
        }

        return parent::getName();
    }

    public function getURI(): string
    {
        $owner = $this->getInput('owner');
        $repo = $this->getInput('repo');

        if ($owner !== null && $repo !== null) {
            return self::URI . $owner . '/' . $repo . '/issues';
        }

        return parent::getURI();
    }

    private function apiHeaders(): array
    {
        $token = $this->resolveToken();
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: RSS-Bridge',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    private function apiRequest(string $url): array
    {
        $json = getContents($url, $this->apiHeaders());
        $data = \Json::decode($json);

        if (isset($data['message']) === true && isset($data['html_url']) === false && array_key_exists('documentation_url', $data) === true) {
            throw new \ServerException('GitHub API error for ' . $url . ': ' . $data['message']);
        }

        return $data;
    }

    private function buildGitHubIssueUri(int $issueNumber): string
    {
        $owner = (string) $this->getInput('owner');
        $repo = (string) $this->getInput('repo');

        return self::URI . $owner . '/' . $repo . '/issues/' . $issueNumber;
    }

    private function markdownToHtml(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $parsedown = new \Parsedown();
        return $parsedown->text($text);
    }

    private function parseSearchQuery(string $query): array
    {
        $sort = null;
        $order = null;

        $query = preg_replace_callback(
            '/\bsort:([a-zA-Z\-]+)\b/',
            function ($m) use (&$sort, &$order) {
                $value = $m[1];
                if (str_ends_with($value, '-desc') === true) {
                    $sort = substr($value, 0, -5);
                    $order = 'desc';
                } elseif (str_ends_with($value, '-asc') === true) {
                    $sort = substr($value, 0, -4);
                    $order = 'asc';
                } else {
                    $sort = $value;
                }
                return '';
            },
            $query
        );

        return [
            'q' => trim(preg_replace('/\s+/', ' ', $query)),
            'sort' => $sort,
            'order' => $order,
        ];
    }

    private function buildIssueItem(array $issue): array
    {
        $item = [];
        $item['uri'] = $issue['html_url'];
        $item['title'] = $issue['title'];
        $item['author'] = $issue['user']['login'] ?? '';
        $item['timestamp'] = strtotime($issue['created_at']);

        $labels = array_map(function ($label) {
            return is_array($label) === true ? ($label['name'] ?? '') : $label;
        }, $issue['labels'] ?? []);

        $content = $this->markdownToHtml($issue['body'] ?? '');
        if (count($labels) > 0) {
            $content = '<p><strong>Labels:</strong> ' . implode(', ', $labels) . '</p>' . $content;
        }

        $item['content'] = $content;
        $item['uid'] = (string) $issue['id'];
        return $item;
    }

    public function collectData(): void
    {
        $parsed = $this->parseSearchQuery((string) $this->getInput('q'));
        $owner = (string) $this->getInput('owner');
        $repo = (string) $this->getInput('repo');
        $limit = (int) ($this->getInput('limit') ?? 10);

        $repoQualifier = 'repo:' . $owner . '/' . $repo;
        $q = trim($parsed['q'] . ' ' . self::SEARCH_TYPE_QUALIFIER . ' ' . $repoQualifier);

        $params = ['q' => $q, 'per_page' => '50'];
        if ($parsed['sort'] !== null) {
            $params['sort'] = $parsed['sort'];
        }
        if ($parsed['order'] !== null) {
            $params['order'] = $parsed['order'];
        }

        $searchUrl = self::API_URI . 'search/issues?' . http_build_query($params);
        $result = $this->apiRequest($searchUrl);
        $issues = $result['items'] ?? [];

        $count = 0;
        foreach ($issues as $issue) {
            if ($count >= $limit) {
                break;
            }

            $this->items[] = $this->buildIssueItem($issue);
            $count++;
        }
    }
}
