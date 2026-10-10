<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\GithubClient;
use function RSSBridge\Exceptions\throwServerException;

final class GithubIssueBridge extends BridgeAbstract
{
    public const NAME = 'GitHub Issues';
    public const URI = 'https://github.com/';
    public const DESCRIPTION = 'Returns the issues of a GitHub project';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 600;

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
    private const ALLOWED_TAGS = '<a><p><br><strong><em><code><pre><blockquote><ul><ol><li><table><thead><tbody><tr><th><td><img><h1><h2><h3><h4><h5><h6><hr><del><details><summary>';

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

    public function collectData(): void
    {
        $parsed = $this->parseSearchQuery((string) $this->getInput('q'));
        $owner = (string) $this->getInput('owner');
        $repo = (string) $this->getInput('repo');
        $limit = max(1, (int) ($this->getInput('limit') ?? 10));

        $repoQualifier = 'repo:' . $owner . '/' . $repo;
        $q = trim($parsed['q'] . ' ' . self::SEARCH_TYPE_QUALIFIER . ' ' . $repoQualifier);

        $params = [
            'q' => $q,
            'per_page' => min(50, $limit),
        ];

        if ($parsed['sort'] !== null) {
            $params['sort'] = $parsed['sort'];
        }
        if ($parsed['order'] !== null) {
            $params['order'] = $parsed['order'];
        }

        $client = new GithubClient($this->cache, $this->logger);

        try {
            $result = $client->searchIssues($params);
        } catch (\Exception $e) {
            throwServerException('GitHub API error: ' . $e->getMessage());
        }

        $count = 0;
        foreach ($result['items'] as $issue) {
            if ($count >= $limit) {
                break;
            }

            $this->items[] = $this->buildIssueItem($issue, $owner, $repo);
            $count++;
        }
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function buildIssueItem(array $issue, string $owner, string $repo): array
    {
        $labels = array_map(function ($label) {
            return is_array($label) === true ? ($label['name'] ?? '') : (string) $label;
        }, $issue['labels'] ?? []);

        $content = GithubClient::renderGitHubMarkdown((string) ($issue['body'] ?? ''), $owner, $repo);

        if (count($labels) > 0) {
            $labelsHtml = '<p><strong>Labels:</strong> ' . htmlspecialchars(implode(', ', $labels)) . '</p>';
            $content = $labelsHtml . $content;
        }

        $timestamp = strtotime((string) $issue['created_at']);

        return [
            'uri' => (string) $issue['html_url'],
            'title' => (string) $issue['title'],
            'author' => (string) ($issue['user']['login'] ?? ''),
            'timestamp' => $timestamp !== false ? $timestamp : time(),
            'content' => strip_tags($content, self::ALLOWED_TAGS),
            'uid' => (string) $issue['id'],
        ];
    }

    /**
     * @return array{q: string, sort: ?string, order: ?string}
     */
    private function parseSearchQuery(string $query): array
    {
        $sort = null;
        $order = null;

        $query = (string) preg_replace_callback(
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
            'q' => trim((string) preg_replace('/\s+/', ' ', $query)),
            'sort' => $sort,
            'order' => $order,
        ];
    }
}
