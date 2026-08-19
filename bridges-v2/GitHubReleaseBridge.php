<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class GitHubReleaseBridge extends BridgeAbstract
{
    const NAME = 'GitHub Releases';
    const URI = 'https://github.com';
    const DESCRIPTION = 'Returns releases for a GitHub repository (excluding tag-only entries)';
    const MAINTAINER = 'LordArrin';
    const CACHE_TIMEOUT = 3600;

    const CONFIGURATION = ['token' => ['required' => false]];

    const PARAMETERS = [[
        'owner' => [
            'name' => 'Owner',
            'type' => 'text',
            'required' => true,
            'title' => 'The name of the repo owner (e.g. RSS-Bridge from https://github.com/RSS-Bridge/rss-bridge)'
        ],
        'repo' => [
            'name' => 'Repository',
            'type' => 'text',
            'required' => true,
            'title' => 'Repo name (e.g. rss-bridge from https://github.com/RSS-Bridge/rss-bridge)'
        ],
        'pre_release' => [
            'name' => 'Include pre-releases',
            'type' => 'checkbox',
            'title' => 'Check this box to include pre-releases in the feed'
        ],
        'hide_assets' => [
            'name' => 'Hide attachments',
            'type' => 'checkbox',
            'title' => 'Check this box to hide attachments from feed items.'
        ],
        'limit' => [
            'name' => 'Posts limit',
            'type' => 'number',
            'defaultValue' => 10
        ],
    ]];

    private const ALLOWED_TAGS = [
        'div', 'a', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'pre', 'blockquote', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'picture', 'source', 'figure', 'figcaption', 'del', 'details', 'summary'
    ];

    private const ERROR_MESSAGES = [
        401 => 'Auth failed',
        403 => 'Rate limit exceeded',
        404 => 'Repo not found',
    ];

    private const CSS = [
        'wrapper' => 'font-size:14px; line-height:1.6; word-wrap:break-word;',
        'alert_base' => 'padding-left:12px; margin:8px 0;',
        'ul' => 'list-style-type:disc; padding-left:24px;',
        'ol' => 'list-style-type:decimal; padding-left:24px;',
        'alerts' => [
            'NOTE' => '#0969da',
            'TIP' => '#1a7f37',
            'IMPORTANT' => '#8250df',
            'WARNING' => '#9a6700',
            'CAUTION' => '#cf222e',
        ],
    ];

    private const FILE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 10;

    public function collectData(): void
    {
        $owner = (string)$this->getInput('owner');
        $repo = (string)$this->getInput('repo');
        $includePrereleases = (bool)$this->getInput('pre_release');
        $hideAssets = (bool)$this->getInput('hide_assets');
        $limit = max(1, min(self::MAX_LIMIT, (int)($this->getInput('limit') ?: self::DEFAULT_LIMIT)));

        $releases = $this->fetchReleases($owner, $repo);

        foreach ($releases as $release) {
            if (count($this->items) >= $limit) {
                break;
            }

            if ($this->shouldSkipRelease($release, $includePrereleases)) {
                continue;
            }

            $this->items[] = $this->buildFeedItem($release, $owner, $repo, $hideAssets);
        }
    }

    public function getName(): string
    {
        $owner = $this->getInput('owner');
        $repo = $this->getInput('repo');

        if ($owner && $repo) {
            return sprintf('%s/%s - Releases', $owner, $repo);
        }

        return parent::getName();
    }

    public function getURI(): string
    {
        $owner = $this->getInput('owner');
        $repo = $this->getInput('repo');

        if ($owner && $repo) {
            return sprintf('%s/%s/%s/releases', self::URI, $owner, $repo);
        }

        return parent::getURI();
    }

    public function detectParameters($url): ?array
    {
        if (!is_string($url)) {
            return null;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';

        $uriHost = parse_url(self::URI, PHP_URL_HOST);
        if ($host !== $uriHost && $host !== 'www.' . $uriHost) {
            return null;
        }

        if (preg_match('#^/([^/]+)/([^/]+?)(?:/(?:releases|tags))?/?$#', $path, $matches)) {
            return ['owner' => $matches[1], 'repo' => $matches[2]];
        }

        return null;
    }

    private function fetchReleases(string $owner, string $repo): array
    {
        $url = sprintf('https://api.%s/repos/%s/%s/releases?per_page=100', parse_url(self::URI, PHP_URL_HOST), $owner, $repo);
        $headers = ['Accept: application/vnd.github+json'];

        $token = $this->getOption('token');
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        try {
            $response = json_decode(getContents($url, $headers), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $code = (int)$e->getCode();
            $message = self::ERROR_MESSAGES[$code] ?? 'GitHub API error: ' . $e->getMessage();
            throwServerException($message);
        }

        if (!is_array($response)) {
            throwServerException('Invalid response from GitHub API');
        }

        if (!empty($response) && !isset($response[0])) {
            $errorMsg = $response['message'] ?? 'Unknown API error';
            throwServerException('GitHub API error: ' . $errorMsg);
        }

        return $response;
    }

    private function shouldSkipRelease(array $release, bool $includePrereleases): bool
    {
        if (!empty($release['draft'])) {
            return true;
        }

        if (!empty($release['prerelease']) && !$includePrereleases) {
            return true;
        }

        return false;
    }

    private function buildFeedItem(array $release, string $owner, string $repo, bool $hideAssets): array
    {
        $title = $release['name'] ?: ($release['tag_name'] ?? 'Untitled');
        $content = !empty($release['body']) ? $this->processMarkdown((string)$release['body'], $owner, $repo) : '';

        if (!$hideAssets && !empty($release['assets']) && is_array($release['assets'])) {
            $assetsHtml = $this->buildAssetsBlock($release['assets']);
            if ($assetsHtml !== '') {
                $content .= $assetsHtml;
            }
        }

        $dateStr = $release['published_at'] ?? $release['created_at'] ?? '';
        $timestamp = strtotime($dateStr) ?: time();

        return [
            'title' => $title,
            'uri' => $release['html_url'] ?? '',
            'content' => $content,
            'timestamp' => $timestamp,
            'author' => $release['author']['login'] ?? '',
            'uid' => $release['tag_name'] ?? (string)($release['id'] ?? uniqid()),
            'enclosures' => [],
            'categories' => [$release['tag_name'] ?? ''],
        ];
    }

    private function buildAssetsBlock(array $assets): string
    {
        $links = [];

        foreach ($assets as $asset) {
            $url = $asset['browser_download_url'] ?? '';
            $name = $asset['name'] ?? '';

            if ($url === '' || $name === '') {
                continue;
            }

            $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $size = $this->formatFileSize((int)($asset['size'] ?? 0));
            $label = $size !== '' ? "{$name} ({$size})" : $name;

            $links[] = "<li><a href=\"{$url}\">{$label}</a></li>";
        }

        if (empty($links)) {
            return '';
        }

        return '<h3>Downloads</h3><ul style="' . self::CSS['ul'] . '">' . implode('', $links) . '</ul>';
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        $index = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $index < count(self::FILE_UNITS) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 2) . ' ' . self::FILE_UNITS[$index];
    }

    private function processMarkdown(string $markdown, string $owner, string $repo): string
    {
        $markdown = $this->enrichMarkdownMentions($markdown, $owner, $repo);

        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(false);
        $parsedown->setMarkupEscaped(false);

        $html = $parsedown->text($markdown);

        return $this->processHtml($html, $owner, $repo);
    }

    private function enrichMarkdownMentions(string $markdown, string $owner, string $repo): string
    {
        $repoUrl = sprintf('%s/%s/%s/issues', self::URI, rawurlencode($owner), rawurlencode($repo));

        $markdown = preg_replace(
            '/(?<!\w)@([a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38})(?!\w)/',
            '[@$1](' . self::URI . '/$1)',
            $markdown
        );

        $markdown = preg_replace(
            '/(?<!\w)#(\d+)(?!\w)/',
            '[#$1](' . $repoUrl . '/$1)',
            $markdown
        );

        return preg_replace('/:[a-zA-Z0-9_+\-]+:/', '', $markdown);
    }

    private function processHtml(string $html, string $owner, string $repo): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="w">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $this->transformAlerts($xpath);
        $this->shortenAutoLinks($xpath, $owner, $repo);
        $this->applyListStyles($xpath);
        $this->sanitizeHtml($xpath);

        $wrapper = $dom->getElementById('w');
        if (!$wrapper) {
            return '';
        }

        $content = $dom->saveHTML($wrapper);
        $content = preg_replace('#^\s*<div[^>]*>#', '', $content);
        $content = preg_replace('#</div>\s*$#', '', $content);

        $allowedTags = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $content = strip_tags(trim($content), $allowedTags);

        return sprintf('<div style="%s">%s</div>', self::CSS['wrapper'], $content);
    }

    private function sanitizeHtml(\DOMXPath $xpath): void
    {
        foreach ($xpath->query('//*') as $node) {
            $attrsToRemove = [];

            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->nodeName);
                $value = strtolower(trim($attr->nodeValue));

                if (str_starts_with($name, 'on')) {
                    $attrsToRemove[] = $attr->nodeName;
                    continue;
                }

                if (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'])) {
                    if (preg_match('/^\s*(javascript|vbscript|data(?!:image\/))/i', $value)) {
                        $attrsToRemove[] = $attr->nodeName;
                    }
                }
            }

            foreach ($attrsToRemove as $attrName) {
                $node->removeAttribute($attrName);
            }

            foreach (['src', 'href'] as $attr) {
                if ($node->hasAttribute($attr)) {
                    $value = $node->getAttribute($attr);
                    if (str_starts_with($value, '/')) {
                        $node->setAttribute($attr, self::URI . $value);
                    }
                }
            }

            if ($node->hasAttribute('srcset')) {
                $srcset = $node->getAttribute('srcset');
                $srcset = preg_replace('#(^|[\s,])(/[^,\s]+)#', '$1' . self::URI . '$2', $srcset);
                $node->setAttribute('srcset', $srcset);
            }
        }
    }

    private function transformAlerts(\DOMXPath $xpath): void
    {
        $types = array_keys(self::CSS['alerts']);
        $pattern = '/\[!(' . implode('|', $types) . ')\]\s*/i';

        foreach ($xpath->query('//blockquote') as $bq) {
            $found = $this->detectAlertType($xpath, $bq, $pattern);

            if (!$found) {
                continue;
            }

            $this->removeAlertMarkers($xpath, $bq, $pattern);

            $color = self::CSS['alerts'][strtoupper($found)];

            $existing = $bq->getAttribute('style');
            $borderStyle = sprintf('border-left:4px solid %s;', $color);
            $style = trim(($existing ? $existing . ' ' : '') . $borderStyle . ' ' . self::CSS['alert_base']);
            $bq->setAttribute('style', $style);
        }
    }

    private function detectAlertType(\DOMXPath $xpath, \DOMElement $bq, string $pattern): ?string
    {
        foreach ($xpath->query('.//text()', $bq) as $node) {
            if (preg_match($pattern, $node->nodeValue, $matches)) {
                return strtoupper($matches[1]);
            }
        }

        foreach ($xpath->query('.//strong', $bq) as $strong) {
            $text = strtolower(trim($strong->textContent));
            $types = array_map('strtolower', array_keys(self::CSS['alerts']));

            if (in_array($text, $types, true)) {
                $strong->parentNode?->removeChild($strong);
                return $text;
            }
        }

        return null;
    }

    private function removeAlertMarkers(\DOMXPath $xpath, \DOMElement $bq, string $pattern): void
    {
        foreach ($xpath->query('.//text()', $bq) as $node) {
            if (preg_match($pattern, $node->nodeValue)) {
                $node->nodeValue = preg_replace($pattern, '', $node->nodeValue);
            }
        }
    }

    private function shortenAutoLinks(\DOMXPath $xpath, string $owner, string $repo): void
    {
        $ownerQuoted = preg_quote($owner, '~');
        $repoQuoted = preg_quote($repo, '~');

        foreach ($xpath->query('//a[@href]') as $link) {
            $href = $link->getAttribute('href');
            $text = trim($link->textContent);

            if ($text !== $href) {
                continue;
            }

            if (preg_match('~^' . preg_quote(self::URI, '~') . '/' . $ownerQuoted . '/' . $repoQuoted . '/(?:issues|pull)/(\d+)(?:[/?#].*)?$~i', $href, $matches)) {
                $link->nodeValue = '#' . $matches[1];
            } elseif (preg_match('~^' . preg_quote(self::URI, '~') . '/([^/]+)/([^/]+)/(?:issues|pull)/(\d+)(?:[/?#].*)?$~i', $href, $matches)) {
                $link->nodeValue = $matches[1] . '/' . $matches[2] . '#' . $matches[3];
            } elseif (preg_match('~^' . preg_quote(self::URI, '~') . '/([a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38})$~', $href, $matches)) {
                $link->nodeValue = '@' . $matches[1];
            }
        }
    }

    private function applyListStyles(\DOMXPath $xpath): void
    {
        foreach ($xpath->query('//ul | //ol') as $list) {
            $existing = $list->getAttribute('style');
            $newStyle = $list->nodeName === 'ul' ? self::CSS['ul'] : self::CSS['ol'];
            $list->setAttribute('style', trim(($existing ? $existing . ' ' : '') . $newStyle));
        }
    }
}
