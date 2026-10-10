<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Utils\Json;
use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;
use RSSBridge\Exceptions\RateLimitException;
use RSSBridge\Http\HttpException;
use RSSBridge\Http\Response;
use RSSBridge\Logger\Logger;

/**
 * GitHub API client with built-in rate limit handling.
 *
 * @see https://docs.github.com/en/rest
 */
final class GithubClient
{
    public const BASE = 'https://api.github.com';
    public const URI = 'https://github.com';

    private CacheInterface $cache;
    private Logger $logger;
    private ?string $token;

    public function __construct(CacheInterface $cache, Logger $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;

        $globalToken = Configuration::getConfig('GitHub', 'token');
        $this->token = is_string($globalToken) === true && $globalToken !== '' ? $globalToken : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchIssues(string $owner, string $repo): array
    {
        $issues = $this->fetch(sprintf('/repos/%s/%s/issues?per_page=30', $owner, $repo));

        return array_values(array_filter($issues, function (array $issue): bool {
            return isset($issue['pull_request']) === false;
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchPullRequests(string $owner, string $repo): array
    {
        return $this->fetch(sprintf('/repos/%s/%s/pulls?per_page=30', $owner, $repo));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchPullRequestComments(string $owner, string $repo, int $id): array
    {
        return $this->fetchIssueComments($owner, $repo, $id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchIssueComments(string $owner, string $repo, int $id): array
    {
        $comments = $this->fetch(sprintf('/repos/%s/%s/issues/%s/comments', $owner, $repo, $id));

        return array_reverse($comments);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchReleases(string $owner, string $repo): array
    {
        return $this->fetch(sprintf('/repos/%s/%s/releases?per_page=100', $owner, $repo));
    }

    /**
     * Fetch repository events (including issue comments).
     * One request returns up to 100 recent events.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchRepositoryEvents(string $owner, string $repo): array
    {
        return $this->fetch(sprintf('/repos/%s/%s/events?per_page=100', $owner, $repo));
    }

    /**
     * Search issues across GitHub using the Search API.
     *
     * @param array<string, mixed> $params Query parameters (q, sort, order, per_page, page)
     * @return array{items: array<int, array<string, mixed>>, total_count: int}
     * @see https://docs.github.com/en/rest/search/search#search-issues-and-pull-requests
     */
    public function searchIssues(array $params): array
    {
        $url = '/search/issues?' . http_build_query($params);
        $result = $this->fetch($url);

        return [
            'items' => $result['items'] ?? [],
            'total_count' => (int) ($result['total_count'] ?? 0),
        ];
    }

    public function fetchTagCommitMessage(string $owner, string $repo, string $tagName): string
    {
        try {
            $response = $this->fetch(sprintf('/repos/%s/%s/commits/%s', $owner, $repo, rawurlencode($tagName)));
            return (string) ($response['commit']['message'] ?? '');
        } catch (HttpException $e) {
            $this->logger->warning(sprintf('github: Failed to fetch commit message for tag %s: %s', $tagName, $e->getMessage()));
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchRateLimit(): array
    {
        return $this->fetch('/rate_limit');
    }

    /**
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    private function fetch(string $url): array
    {
        $cacheKey = $this->token !== null ? 'github_rate_limit_auth' : 'github_rate_limit_anon';

        if ($this->cache->get($cacheKey) === true) {
            $this->logger->info(sprintf('github: Internal rate limit (%s)', $this->token !== null ? 'authenticated' : 'anonymous'));
            throw new RateLimitException(sprintf('Internal github rate limit (%s)', $this->token !== null ? 'authenticated' : 'anonymous'));
        }

        $this->logger->info(sprintf('github: github_client->fetch(%s)', $url));

        $headers = ['Accept: application/vnd.github+json'];
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        try {
            /** @var Response $response */
            $response = getContents(self::BASE . $url, $headers, [], true);

            $limit = (string) ($response->getHeader('x-ratelimit-limit') ?? 'unknown');
            $remaining = (string) ($response->getHeader('x-ratelimit-remaining') ?? 'unknown');
            $used = (string) ($response->getHeader('x-ratelimit-used') ?? 'unknown');
            $reset = (string) ($response->getHeader('x-ratelimit-reset') ?? 'unknown');

            $this->logger->info(sprintf(
                'github: limit=%s, remaining=%s, used=%s, reset=%s (%s)',
                $limit,
                $remaining,
                $used,
                $reset,
                $this->token !== null ? 'authenticated' : 'anonymous'
            ));

            if ($remaining === '0') {
                $this->logger->info(sprintf('github: REAL rate limit (0 remaining) - %s', $this->token !== null ? 'authenticated' : 'anonymous'));
                $this->cache->set($cacheKey, true, 60 * 60);
                throw new RateLimitException(sprintf('REAL github rate limit (0 remaining) - %s', $this->token !== null ? 'authenticated' : 'anonymous'));
            }
        } catch (HttpException $e) {
            $code = $e->getCode();

            if ($code === 401) {
                $this->logger->error('github: Authentication failed');
                throw new \Exception('Auth failed');
            }

            if ($code === 404) {
                $this->logger->error('github: Resource not found');
                throw new \Exception('Repo not found');
            }

            if (in_array($code, [403, 429], true) === true) {
                $this->logger->info(sprintf('github: REAL rate limit - %s', $this->token !== null ? 'authenticated' : 'anonymous'));
                $this->cache->set($cacheKey, true, 60 * 60);
                throw new RateLimitException(sprintf('REAL github rate limit - %s', $this->token !== null ? 'authenticated' : 'anonymous'));
            }

            throw $e;
        }

        return Json::decode($response->getBody());
    }

    /**
     * Converts bare URLs in HTML text to clickable <a> tags.
     * Works reliably even inside <code>, <pre>, and table cells.
     */
    public static function linkifyBareUrls(string $html): string
    {
        $parts = preg_split('#(<[^>]+>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $inAnchor = false;
        $result = '';

        foreach ($parts as $part) {
            if (str_starts_with($part, '<') === true) {
                $result .= $part;
                if (preg_match('#^<(/?)a[\s>]#i', $part, $m) === 1) {
                    $inAnchor = $m[1] === '';
                }
                continue;
            }

            if ($inAnchor === true) {
                $result .= $part;
                continue;
            }

            $linked = preg_replace(
                '#(https?://[^\s<>"\']+)#i',
                '<a href="$1" target="_blank" rel="noopener">$1</a>',
                $part
            );
            $result .= $linked !== null ? $linked : $part;
        }

        return $result;
    }

    /**
     * Applies GitHub-style rendering to markdown content:
     * - @mentions → profile links
     * - #issue references → repo issue links
     * - emoji shortcodes removed
     * - tables styled with borders
     * - bare URLs linkified
     */
    public static function renderGitHubMarkdown(string $markdown, string $owner, string $repo): string
    {
        if ($markdown === '') {
            return '';
        }

        $result = preg_replace(
            '/(?<!\w)@([a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38})(?!\w)/',
            '[@$1](https://github.com/$1)',
            $markdown
        );
        $markdown = $result !== null ? $result : $markdown;

        $repoUrl = sprintf('%s%s/%s/issues', self::URI, rawurlencode($owner), rawurlencode($repo));
        $result = preg_replace(
            '/(?<!\w)#(\d+)(?!\w)/',
            '[#$1](' . $repoUrl . '/$1)',
            $markdown
        );
        $markdown = $result !== null ? $result : $markdown;

        $result = preg_replace('/:[a-zA-Z_+\-][a-zA-Z0-9_+\-]*:/', '', $markdown);
        $markdown = $result !== null ? $result : $markdown;

        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(false);
        $parsedown->setMarkupEscaped(false);

        $html = $parsedown->text($markdown);

        $replacements = [
            '<table>' => '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;border:1px solid #d0d7de;margin:12px 0">',
            '<th>'    => '<th style="background-color:#f6f8fa;padding:6px 13px;border:1px solid #d0d7de;text-align:left;font-weight:bold">',
            '<td>'    => '<td style="padding:6px 13px;border:1px solid #d0d7de">',
        ];
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);

        return self::linkifyBareUrls($html);
    }
}
