<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\GithubClient;
use function RSSBridge\Exceptions\throwClientException;
use function RSSBridge\Exceptions\throwServerException;

final class GitHubPullRequestBridge extends BridgeAbstract
{
    public const NAME = 'GitHub Pull Request';
    public const URI = 'https://github.com/';
    public const DESCRIPTION = 'Returns the pull requests or comments of a pull request of a GitHub project';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 86400;

    public const PARAMETERS = [
        'global' => [
            'u' => [
                'name' => 'User name',
                'exampleValue' => 'RSS-Bridge',
                'required' => true,
            ],
            'p' => [
                'name' => 'Project name',
                'exampleValue' => 'rss-bridge',
                'required' => true,
            ],
        ],
        'Project Pull Requests' => [
            'c' => [
                'name' => 'Show Pull Request Comments',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Show recent comments for all pull requests using Events API (one request)',
            ],
        ],
        'Pull Request comments' => [
            'i' => [
                'name' => 'Pull Request number',
                'type' => 'number',
                'exampleValue' => '2100',
                'required' => true,
            ],
        ],
    ];

    private const ALLOWED_TAGS = '<a><p><br><strong><em><code><pre><blockquote><ul><ol><li><table><thead><tbody><tr><th><td><img><h1><h2><h3><h4><h5><h6><hr><del><details><summary>';

    public function getName(): string
    {
        $owner = (string) $this->getInput('u');
        $repo = (string) $this->getInput('p');

        if ($owner !== '' && $repo !== '') {
            return sprintf('%s/%s - Pull Requests', $owner, $repo);
        }

        return parent::getName();
    }

    public function getURI(): string
    {
        $owner = $this->getInput('u');
        $repo = $this->getInput('p');

        if ($owner !== null && $repo !== null && (string) $owner !== '' && (string) $repo !== '') {
            return self::URI . $owner . '/' . $repo . '/pulls';
        }

        return parent::getURI();
    }

    public function collectData(): void
    {
        $owner = (string) $this->getInput('u');
        $repo = (string) $this->getInput('p');

        if ($owner === '' || $repo === '') {
            throwClientException('User name and Project name are required.');
        }

        $client = new GithubClient($this->cache, $this->logger);

        $items = match ($this->queriedContext) {
            'Project Pull Requests' => $this->fetchProjectPullRequests($client, $owner, $repo),
            'Pull Request comments' => $this->fetchSinglePrComments($client, $owner, $repo, (int) $this->getInput('i')),
            default => [],
        };

        foreach ($items as $item) {
            $timestamp = strtotime((string) ($item['created_at'] ?? ''));
            if ($timestamp === false) {
                $timestamp = time();
            }

            $this->items[] = [
                'title' => (string) ($item['title'] ?? 'Untitled'),
                'uri' => (string) ($item['html_url'] ?? ''),
                'author' => (string) ($item['user']['login'] ?? ''),
                'timestamp' => $timestamp,
                'content' => $this->renderMarkdown((string) ($item['body'] ?? ''), $owner, $repo),
                'uid' => (string) ($item['html_url'] ?? uniqid()),
            ];
        }

        if ($this->items === []) {
            throwServerException('No items found.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProjectPullRequests(GithubClient $client, string $owner, string $repo): array
    {
        $showComments = (bool) $this->getInput('c');

        if ($showComments === false) {
            return $client->fetchPullRequests($owner, $repo);
        }

        $events = $client->fetchRepositoryEvents($owner, $repo);
        $comments = [];

        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'IssueCommentEvent') {
                continue;
            }

            $issue = $event['payload']['issue'] ?? [];
            if (isset($issue['pull_request']) === false) {
                continue;
            }

            $comment = $event['payload']['comment'] ?? [];
            if ($comment === []) {
                continue;
            }

            $comments[] = [
                'created_at' => $event['created_at'] ?? '',
                'html_url' => $comment['html_url'] ?? '',
                'user' => $comment['user'] ?? [],
                'body' => $comment['body'] ?? '',
                'title' => sprintf('[PR #%d] %s', $issue['number'] ?? 0, $issue['title'] ?? 'Comment'),
            ];
        }

        return $comments;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSinglePrComments(GithubClient $client, string $owner, string $repo, int $id): array
    {
        $comments = $client->fetchPullRequestComments($owner, $repo, $id);

        return array_map(function (array $comment) use ($id): array {
            $comment['title'] = sprintf(
                'Comment by @%s on PR #%d',
                $comment['user']['login'] ?? 'unknown',
                $id
            );
            return $comment;
        }, $comments);
    }

    private function renderMarkdown(string $markdown, string $owner, string $repo): string
    {
        if ($markdown === '') {
            return '';
        }

        // Convert @mentions to profile links
        $result = preg_replace(
            '/(?<!\w)@([a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38})(?!\w)/',
            '[@$1](https://github.com/$1)',
            $markdown
        );
        $markdown = $result !== null ? $result : $markdown;

        // Convert #issue references to links
        $repoUrl = sprintf('%s%s/%s/issues', self::URI, rawurlencode($owner), rawurlencode($repo));
        $result = preg_replace(
            '/(?<!\w)#(\d+)(?!\w)/',
            '[#$1](' . $repoUrl . '/$1)',
            $markdown
        );
        $markdown = $result !== null ? $result : $markdown;

        // Remove emoji shortcodes (must start with letter/symbol, not digit)
        // Prevents stripping time-like sequences such as 05:02:49
        $result = preg_replace('/:[a-zA-Z_+\-][a-zA-Z0-9_+\-]*:/', '', $markdown);
        $markdown = $result !== null ? $result : $markdown;

        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(false);
        $parsedown->setMarkupEscaped(false);

        $html = $parsedown->text($markdown);
        $html = $this->styleTables($html);
        $html = $this->linkifyBareUrls($html);

        return strip_tags($html, self::ALLOWED_TAGS);
    }

    private function styleTables(string $html): string
    {
        $replacements = [
            '<table>' => '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;border:1px solid #d0d7de;margin:12px 0">',
            '<th>'    => '<th style="padding:8px 14px;border:1px solid #d0d7de;text-align:left;font-weight:bold">',
            '<td>'    => '<td style="padding:8px 14px;border:1px solid #d0d7de">',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /**
     * Converts bare URLs in HTML text to clickable <a> tags.
     * Skips URLs already wrapped in existing <a>...</a> blocks.
     * Works reliably even inside <code>, <pre>, and table cells.
     */
    private function linkifyBareUrls(string $html): string
    {
        // Split HTML into tags and text segments
        $parts = preg_split('#(<[^>]+>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $inAnchor = false;
        $result = '';

        foreach ($parts as $part) {
            // If it's an HTML tag, track anchor state
            if (str_starts_with($part, '<') === true) {
                $result .= $part;
                if (preg_match('#^<(/?)a[\s>]#i', $part, $m) === 1) {
                    $inAnchor = $m[1] === '';
                }
                continue;
            }

            // Skip linkification inside existing <a>...</a> blocks
            if ($inAnchor === true) {
                $result .= $part;
                continue;
            }

            // Linkify bare URLs in this text segment
            $linked = preg_replace(
                '#(https?://[^\s<>"\']+)#i',
                '<a href="$1" target="_blank" rel="noopener">$1</a>',
                $part
            );
            $result .= $linked !== null ? $linked : $part;
        }

        return $result;
    }
}
