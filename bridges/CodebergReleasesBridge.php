<?php

declare(strict_types=1);

final class CodebergReleasesBridge extends BridgeAbstract
{
    public const NAME = 'Codeberg Releases';
    public const URI = 'https://codeberg.org/';
    public const DESCRIPTION = 'Returns releases for a Codeberg repository with changelogs and downloads';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'owner' => [
                'name' => 'Owner',
                'title' => 'User or organization',
                'exampleValue' => 'librewolf',
                'required' => true,
                'pattern' => '[A-Za-z0-9_.-]+',
            ],
            'repo' => [
                'name' => 'Repository',
                'exampleValue' => 'bsys6',
                'required' => true,
                'pattern' => '[A-Za-z0-9_.-]+',
            ],
            'limit' => [
                'name' => 'Maximum releases',
                'type' => 'number',
                'defaultValue' => 5,
                'required' => true,
            ],
            'include_prereleases' => [
                'name' => 'Pre-releases',
                'type' => 'list',
                'values' => ['Include' => '1', 'Exclude' => '0'],
                'defaultValue' => '1',
            ],
            'downloads_mode' => [
                'name' => 'Download links',
                'type' => 'list',
                'values' => ['Hide' => 'hide', 'Inline' => 'inline', 'Enclosures' => 'enclosures'],
                'defaultValue' => 'inline',
            ],
            'downloads_filter' => [
                'name' => 'Filter downloads',
                'title' => 'Space, comma or newline separated patterns. Supports * and ? wildcards. Leave empty to show all.',
                'type' => 'text',
                'exampleValue' => 'x86_64-msix.msix *.apk',
                'required' => false,
            ],
        ],
    ];

    private const int API_LIMIT = 50;
    private const int MAX_PAGES = 5;

    public function collectData(): void
    {
        [$owner, $repo] = $this->repository();
        $this->validateSlug($owner);
        $this->validateSlug($repo);

        $limit = max(1, min(50, (int) ($this->getInput('limit') ?: 10)));

        $prereleasesInput = strtolower(trim((string) $this->getInput('include_prereleases')));
        $excludeValues = ['0', 'false', 'off', 'no', 'exclude', 'disabled'];
        $includePrereleases = in_array($prereleasesInput, $excludeValues, true) === false;

        $downloadsInput = strtolower(trim((string) $this->getInput('downloads_mode')));
        $downloadsMode = match ($downloadsInput) {
            'hide', 'none', '0', 'off', 'false' => 'hide',
            'enclosures', 'enclosure' => 'enclosures',
            default => 'inline',
        };

        $downloadsFilter = $this->parseFilter((string) $this->getInput('downloads_filter'));

        for ($page = 1; $page <= self::MAX_PAGES && count($this->items) < $limit; ++$page) {
            $url = sprintf(
                '%s/api/v1/repos/%s/%s/releases?limit=%d&page=%d',
                rtrim(self::URI, '/'),
                rawurlencode($owner),
                rawurlencode($repo),
                self::API_LIMIT,
                $page
            );

            $releases = $this->fetchJson($url);

            if ($releases === []) {
                break;
            }

            foreach ($releases as $release) {
                if (is_array($release) === false || ($release['draft'] ?? false) === true) {
                    continue;
                }

                if ($includePrereleases === false && ($release['prerelease'] ?? false) === true) {
                    continue;
                }

                $this->items[] = $this->buildItem($release, $owner, $repo, $downloadsMode, $downloadsFilter);

                if (count($this->items) >= $limit) {
                    break;
                }
            }

            if (count($releases) < self::API_LIMIT) {
                break;
            }
        }
    }

    public function getURI(): string
    {
        [$owner, $repo] = $this->repository();

        if ($owner !== '' && $repo !== '') {
            return sprintf('%s%s/%s/releases', self::URI, rawurlencode($owner), rawurlencode($repo));
        }

        return self::URI;
    }

    public function getName(): string
    {
        [$owner, $repo] = $this->repository();

        if ($owner !== '' && $repo !== '') {
            return sprintf('%s/%s releases', $owner, $repo);
        }

        return self::NAME;
    }

    private function repository(): array
    {
        return [
            trim((string) $this->getInput('owner')),
            trim((string) $this->getInput('repo')),
        ];
    }

    private function validateSlug(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $value) === 0) {
            throw new InvalidArgumentException(
                'Owner and repository may contain letters, digits, dot, dash and underscore only.'
            );
        }
    }

    private function fetchJson(string $url): array
    {
        $headers = ['Accept: application/json', 'User-Agent: RSS-Bridge CodebergReleasesBridge'];
        $data = json_decode((string) getContents($url, $headers), true, 512, JSON_THROW_ON_ERROR);

        if (is_array($data) === true && array_is_list($data) === true) {
            return $data;
        }

        throw new RuntimeException('Codeberg API returned an unexpected response.');
    }

    private function parseFilter(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [];
        }

        $result = [];

        foreach ($parts as $part) {
            $trimmed = trim($part);

            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    private function matchesFilter(string $name, array $filter): bool
    {
        if ($filter === []) {
            return true;
        }

        foreach ($filter as $pattern) {
            if (fnmatch($pattern, $name, FNM_CASEFOLD) === true) {
                return true;
            }
        }

        return false;
    }

    private function buildItem(array $release, string $owner, string $repo, string $downloadsMode, array $downloadsFilter): array
    {
        $tag = (string) ($release['tag_name'] ?? '');
        $name = trim((string) ($release['name'] ?? ''));

        if ($name !== '') {
            $title = $name;
        } elseif ($tag !== '') {
            $title = $tag;
        } else {
            $title = 'Release';
        }

        $uri = $this->absoluteUrl((string) ($release['html_url'] ?? ''));

        if ($uri === '') {
            $uri = sprintf(
                '%s%s/%s/releases/tag/%s',
                self::URI,
                rawurlencode($owner),
                rawurlencode($repo),
                rawurlencode($tag)
            );
        }

        $timestamp = strtotime((string) ($release['published_at'] ?? $release['created_at'] ?? ''));
        if ($timestamp === false) {
            $timestamp = time();
        }

        $categories = array_values(array_filter([
            $tag !== '' ? $tag : null,
            ($release['prerelease'] ?? false) === true ? 'pre-release' : null,
        ]));

        $content = $this->buildContent($release, $downloadsMode, $downloadsFilter);
        if ($content === '') {
            $content = $this->escape($title);
        }

        $enclosures = [];
        if ($downloadsMode === 'enclosures') {
            $enclosures = $this->extractEnclosures($release, $downloadsFilter);
        }

        return [
            'uri' => $uri,
            'title' => $title,
            'timestamp' => $timestamp,
            'author' => (string) ($release['author']['login'] ?? $owner),
            'content' => $content,
            'enclosures' => $enclosures,
            'categories' => $categories,
            'uid' => (string) ($release['id'] ?? $uri),
        ];
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);

        return match (true) {
            $url === '' => '',
            str_starts_with($url, 'http://') || str_starts_with($url, 'https://') => $url,
            str_starts_with($url, '//') => 'https:' . $url,
            str_starts_with($url, '/') => rtrim(self::URI, '/') . $url,
            default => self::URI . $url,
        };
    }

    private function extractEnclosures(array $release, array $downloadsFilter): array
    {
        $assets = array_filter((array) ($release['assets'] ?? []), is_array(...));
        $urls = [];

        foreach ($assets as $asset) {
            $assetName = trim((string) ($asset['name'] ?? ''));
            $assetUrl = $this->absoluteUrl((string) ($asset['browser_download_url'] ?? ''));

            if ($assetUrl === '') {
                continue;
            }

            $nameForFilter = $assetName;

            if ($nameForFilter === '') {
                $path = parse_url($assetUrl, PHP_URL_PATH);
                $nameForFilter = is_string($path) === true ? basename($path) : '';
            }

            if ($this->matchesFilter($nameForFilter, $downloadsFilter) === true) {
                $urls[] = $assetUrl;
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    private function buildContent(array $release, string $downloadsMode, array $downloadsFilter): string
    {
        $content = '';

        if (($release['prerelease'] ?? false) === true) {
            $content .= '<p><em>This is a pre-release.</em></p>';
        }

        $body = (string) ($release['body'] ?? '');

        if ($body !== '') {
            $body = str_replace(["\r\n", "\r"], "\n", $body);
            $body = preg_replace('/^\s*\(Built by workflow.*$/m', '', $body);
            $body = preg_replace('/^\s*Built by workflow.*$/m', '', $body);
            $body = preg_replace('/\n{3,}/', "\n\n", $body);
            $body = preg_replace('/\n([*-] )/', "\n\n$1", $body);
            $body = trim((string) $body);

            if ($body !== '') {
                $html = markdownToHtml($body, [
                    'breaksEnabled' => false,
                    'markupEscaped' => true,
                    'urlsLinked' => true,
                ]);

                $html = preg_replace(
                    '/<ul([^>]*)>/',
                    '<ul$1 style="list-style-type: disc; margin: 8px 0; padding-left: 24px;">',
                    $html
                );
                $html = preg_replace(
                    '/<li([^>]*)>/',
                    '<li$1 style="margin: 4px 0;">',
                    $html
                );

                $content .= sprintf('<div>%s</div>', $html);
            }
        }

        if ($downloadsMode === 'inline') {
            $content .= $this->buildDownloadsHtml($release, $downloadsFilter);
        }

        return $content;
    }

    private function buildDownloadsHtml(array $release, array $downloadsFilter): string
    {
        $items = [];

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (is_array($asset) === false) {
                continue;
            }

            $url = $this->absoluteUrl((string) ($asset['browser_download_url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $name = trim((string) ($asset['name'] ?? ''));

            if ($name === '') {
                $path = parse_url($url, PHP_URL_PATH);
                $name = is_string($path) === true ? basename($path) : '';
            }

            if ($this->matchesFilter($name, $downloadsFilter) === false) {
                continue;
            }

            $meta = [];

            if (isset($asset['size']) && (float) $asset['size'] > 0.0) {
                $meta[] = $this->formatBytes((float) $asset['size']);
            }

            if (isset($asset['download_count']) && (int) $asset['download_count'] > 0) {
                $meta[] = sprintf('%s downloads', number_format((int) $asset['download_count']));
            }

            $items[] = sprintf(
                '<li><a href="%s">%s</a>%s</li>',
                $this->escape($url),
                $this->escape($name !== '' ? $name : 'download'),
                $meta !== [] ? ' <small>(' . $this->escape(implode(', ', $meta)) . ')</small>' : ''
            );
        }

        if ($items === []) {
            return '';
        }

        return '<h4>Downloads</h4><ul>' . implode('', $items) . '</ul>';
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes <= 0.0) {
            return '0 B';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $index = max(0, min((int) floor(log($bytes, 1024)), count($units) - 1));
        $value = $bytes / (1024 ** $index);

        if ($index === 0 || $value >= 100.0) {
            $decimals = 0;
        } else {
            $decimals = 1;
        }

        return sprintf(
            '%s %s',
            number_format($value, $decimals, '.', ''),
            $units[$index]
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
