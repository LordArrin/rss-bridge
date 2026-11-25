<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;
use RSSBridge\Utils\Url;

final class GiteaReleasesBridge extends BridgeAbstract
{
    public const NAME = 'Gitea Releases';
    public const URI = 'https://gitea.io';
    public const DESCRIPTION = 'Returns releases with download links from a Gitea repository';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 1800;

    public const CONFIGURATION = [
        'api_token' => ['required' => false],
    ];

    public const PARAMETERS = [
        '' => [
            'url' => [
                'name' => 'Repository URL',
                'exampleValue' => 'https://gitea.angry.im/PeterCxy/OpenEUICC',
                'required' => true,
                'title' => 'Repository URL, with or without /releases',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 5,
                'title' => 'Maximum number of releases to return (max 50)',
            ],
            'downloads_mode' => [
                'name' => 'Download links',
                'type' => 'list',
                'values' => ['Inline' => 'inline', 'Enclosures' => 'enclosures', 'Hide' => 'hide'],
                'defaultValue' => 'inline',
            ],
            'downloads_filter' => [
                'name' => 'Filter downloads',
                'type' => 'text',
                'title' => 'Comma-separated patterns (OR). + joins with AND, * is a wildcard, -prefix excludes. Empty shows all.',
                'exampleValue' => '*.apk, -*.zip',
            ],
        ],
    ];

    public function getIcon(): string
    {
        $repo = $this->repoFromInput();
        if ($repo !== null) {
            return $repo[0] . '/assets/img/favicon.png';
        }
        return 'https://gitea.io/images/gitea.png';
    }

    public function getURI(): string
    {
        $repo = $this->repoFromInput();
        if ($repo !== null) {
            return $repo[0] . '/' . rawurlencode($repo[1]) . '/' . rawurlencode($repo[2]) . '/releases';
        }
        return parent::getURI();
    }

    public function getName(): string
    {
        $repo = $this->repoFromInput();
        if ($repo !== null) {
            return $repo[1] . '/' . $repo[2] . ' releases';
        }
        return parent::getName();
    }

    public function collectData(): void
    {
        $repo = $this->repoFromInput();
        if ($repo === null) {
            throwClientException('Provide a valid repository URL, e.g. https://gitea.example.com/owner/repo');
        }
        [$host, $owner, $repoName] = $repo;
        $limit = max(1, min(50, (int) ($this->getInput('limit') ?? 10)));
        $mode = $this->downloadsMode();
        $filter = trim((string) $this->getInput('downloads_filter'));
        $apiUrl = $host . '/api/v1/repos/' . rawurlencode($owner) . '/' . rawurlencode($repoName) . '/releases?' . http_build_query(['limit' => (string) $limit]);

        foreach ($this->fetchJson($apiUrl) as $release) {
            if (is_array($release) === false) {
                continue;
            }
            $tag = $this->text($release['tag_name'] ?? null);
            $name = $this->text($release['name'] ?? null);
            $title = $name !== '' ? $name : $tag;
            if ($title === '') {
                continue;
            }
            $htmlUrl = $this->text($release['html_url'] ?? null);
            $uri = $htmlUrl !== '' ? $htmlUrl : $host . '/' . rawurlencode($owner) . '/' . rawurlencode($repoName) . '/releases/tag/' . rawurlencode($tag);

            $assets = $this->filteredAssets($release, $filter);

            $content = '';
            $body = $this->text($release['body'] ?? null);
            if ($body !== '') {
                $content .= markdownToHtml($body, ['breaksEnabled' => true]);
            }

            $enclosures = [];
            if ($mode === 'inline') {
                $content .= $this->downloadsHtml($assets, $release, $filter);
            } elseif ($mode === 'enclosures') {
                foreach ($assets as $asset) {
                    $enclosures[] = $asset['url'];
                }
            }

            $item = [
                'title' => $title,
                'uri' => $uri,
                'uid' => $tag !== '' ? $tag : $uri,
                'author' => $this->text(read_json_path($release, 'author', 'login')),
            ];
            if ($content !== '') {
                $item['content'] = $content;
            }
            $timestamp = $this->timestamp($release['published_at'] ?? null);
            if ($timestamp === 0) {
                $timestamp = $this->timestamp($release['created_at'] ?? null);
            }
            if ($timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }
            if ($enclosures !== []) {
                $item['enclosures'] = $enclosures;
            }
            $this->items[] = $item;
        }

        if ($this->items === []) {
            throwServerException('No releases found for this repository');
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function repoFromInput(): ?array
    {
        $url = trim((string) $this->getInput('url'));
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (is_array($parts) === false || isset($parts['scheme'], $parts['host']) === false) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $path = trim($parts['path'] ?? '', '/');
        if ($path === '') {
            return null;
        }
        $segments = explode('/', $path);
        if (count($segments) < 2) {
            return null;
        }
        $owner = rawurldecode($segments[0]);
        $repoName = rawurldecode($segments[1]);
        if (str_ends_with($repoName, '.git') === true) {
            $repoName = substr($repoName, 0, -4);
        }
        if ($owner === '' || $repoName === '') {
            return null;
        }
        $host = $scheme . '://' . $parts['host'];
        if (isset($parts['port']) === true) {
            $host .= ':' . $parts['port'];
        }
        return [$host, $owner, $repoName];
    }

    /**
     * @param array<array-key, mixed> $release
     * @return list<array{name: string, url: string, size: int}>
     */
    private function filteredAssets(array $release, string $filter): array
    {
        $result = [];
        $assets = $release['assets'] ?? null;
        if (is_array($assets) === false) {
            return $result;
        }
        foreach ($assets as $asset) {
            if (is_array($asset) === false) {
                continue;
            }
            $url = $this->text($asset['browser_download_url'] ?? null);
            if ($url === '') {
                continue;
            }
            $name = $this->text($asset['name'] ?? null);
            if ($name === '') {
                $name = $this->urlBasename($url);
            }
            if ($this->passesFilter($name, $filter) === false) {
                continue;
            }
            $size = (isset($asset['size']) === true && is_int($asset['size']) === true) ? $asset['size'] : 0;
            $result[] = ['name' => $name, 'url' => $url, 'size' => $size];
        }
        return $result;
    }

    /**
     * @param list<array{name: string, url: string, size: int}> $assets
     * @param array<array-key, mixed> $release
     */
    private function downloadsHtml(array $assets, array $release, string $filter): string
    {
        $entries = [];
        foreach ($assets as $asset) {
            $sizeLabel = $asset['size'] > 0 ? ' (' . Url::formatBytes($asset['size']) . ')' : '';
            $entries[] = '<li><a href="' . e($asset['url']) . '">' . e($asset['name']) . '</a>' . e($sizeLabel) . '</li>';
        }
        if (($release['hide_archive_links'] ?? false) !== true) {
            $zip = $this->text($release['zipball_url'] ?? null);
            $tar = $this->text($release['tarball_url'] ?? null);
            if ($zip !== '' && $this->passesFilter($this->urlBasename($zip), $filter) === true) {
                $entries[] = '<li><a href="' . e($zip) . '">Source code (zip)</a></li>';
            }
            if ($tar !== '' && $this->passesFilter($this->urlBasename($tar), $filter) === true) {
                $entries[] = '<li><a href="' . e($tar) . '">Source code (tar.gz)</a></li>';
            }
        }
        if ($entries === []) {
            return '';
        }
        return '<p><strong>Downloads</strong></p><ul>' . implode('', $entries) . '</ul>';
    }

    private function passesFilter(string $name, string $rules): bool
    {
        if ($rules === '') {
            return true;
        }
        return matches_keyword_rules($name, $rules);
    }

    private function urlBasename(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) === true ? basename($path) : '';
    }

    private function downloadsMode(): string
    {
        $value = strtolower(trim((string) $this->getInput('downloads_mode')));
        return match ($value) {
            'hide' => 'hide',
            'enclosures' => 'enclosures',
            default => 'inline',
        };
    }

    /**
     * @return list<mixed>
     */
    private function fetchJson(string $url): array
    {
        $headers = ['Accept' => 'application/json'];
        $token = $this->getOption('api_token');
        if (is_string($token) === true && $token !== '') {
            $headers['Authorization'] = 'token ' . $token;
        }
        $body = (string) getContents($url, $headers);
        $trimmed = ltrim($body);
        if ($trimmed === '' || (str_starts_with($trimmed, '[') === false && str_starts_with($trimmed, '{') === false)) {
            throwServerException('Gitea API returned a non-JSON response (likely an anti-bot/Cloudflare challenge or an incorrect URL). Verify the repository URL and that the instance is reachable.');
        }
        $data = Json::decode($body);
        if (is_array($data) === false) {
            throwServerException('Unexpected response from the Gitea API');
        }
        return array_values($data);
    }

    private function text(mixed $value): string
    {
        return is_string($value) === true ? trim($value) : '';
    }

    private function timestamp(mixed $value): int
    {
        if (is_string($value) === true && $value !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return 0;
    }
}
