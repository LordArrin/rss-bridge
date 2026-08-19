<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class PawchiveBridge extends BridgeAbstract
{
    const NAME = 'Pawchive';
    const URI = 'https://pawchive.pw/';
    const DESCRIPTION = 'Returns posts from Pawchive. Kemono is dead, long live the Pawchive!';
    const MAINTAINER = 'LordArrin';
    const CACHE_TIMEOUT = 3600;

    const API_PREFIX = 'api/v1/';

    const PARAMETERS = [[
        'service' => [
            'name' => 'Content service',
            'type' => 'list',
            'defaultValue' => 'patreon',
            'values' => self::ACTIVE_SERVICES,
            'title' => 'Pawchive now support only Patreon and Pixiv Fanbox'
        ],
        'user' => [
            'name' => 'Creator ID',
            'type' => 'number',
            'required' => true
        ],
        'q' => [
            'name' => 'Search query',
            'type' => 'text',
            'required' => false
        ],
        'limit' => self::LIMIT,
        'hide_videos' => [
            'name' => 'Hide videos & attachments',
            'type' => 'checkbox',
            'title' => 'Show only image previews. Videos, full-size images and file attachments will be completely hidden',
            'defaultValue' => false
        ],
        'hide_empty' => [
            'name' => 'Hide posts without media',
            'type' => 'checkbox',
            'title' => 'Skip posts without media (text-only posts will be hidden)',
            'defaultValue' => false
        ],
    ]];

    private const array ALL_SERVICES = [
        'Pixiv Fanbox' => 'fanbox',
        'Patreon' => 'patreon',
        'Fantia' => 'fantia',
        'Boosty' => 'boosty',
        'Gumroad' => 'gumroad',
        'SubscribeStar' => 'subscribestar',
        'OnlyFans' => 'onlyfans',
        'Discord' => 'discord',
        'Fansly' => 'fansly',
    ];

    private const array ACTIVE_SERVICES = [
        'Pixiv Fanbox' => 'fanbox',
        'Patreon' => 'patreon',
    ];

    private const array MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'jfif' => 'image/jpeg',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff',
        'ico' => 'image/x-icon',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'm4v' => 'video/x-m4v',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'txt' => 'text/plain',
        'psd' => 'application/x-photoshop',
    ];

    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif', 'bmp', 'svg', 'tiff', 'tif', 'ico'];
    private const array VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'];

    private const array CSS = [
        'image' => 'max-width:100%;height:auto;display:block;margin:0',
        'video' => 'max-width:100%;height:auto;display:block;margin:0',
        'file-link' => 'display:inline-block;margin:0;color:#0066cc;text-decoration:none',
        'url-link' => 'color:#0066cc;text-decoration:none;word-break:break-all',
        'file-container' => 'margin:10px 0',
        'external-link-container' => 'margin:10px 0;padding:10px;border:1px solid #444;border-radius:5px',
        'attachments-container' => 'margin-top:20px;padding:10px',
        'attachments-heading' => 'margin:0 0 8px 0;font-weight:bold',
        'attachments-list' => 'margin:0;padding:0;list-style:none',
        'attachments-item' => 'margin:4px 0',
    ];

    private const array SANITIZE_TAGS_TO_REMOVE = [
        'script',
        'iframe',
        'input',
        'form',
        'head',
        'title',
        'meta',
        'link',
        'style',
        'object',
        'embed',
        'applet',
        'noscript',
    ];

    private const array SANITIZE_ATTRIBUTES_TO_KEEP = [
        'title',
        'href',
        'src',
        'alt',
        'style',
        'class',
        'id',
        'width',
        'height',
        'controls',
        'poster',
        'type',
        'target',
        'rel',
        'loading',
        'decoding',
        'srcset',
        'sizes',
        'data-src',
        'data-srcset',
        'data-lazy-src',
        'data-orig-file',
        'autoplay',
        'loop',
        'muted',
        'playsinline',
        'preload',
    ];

    const CONFIGURATION = [
        'session' => [
            'required' => true,
        ],
    ];

    private const array DOMAINS = ['pawchive.pw'];
    private const string CACHE_KEY_ACTIVE_DOMAIN = 'active_domain';

    private ?string $author = null;
    private ?string $authorAvatarUrl = null;
    private array $mimeCache = [];
    private ?string $activeDomainHost = null;

    private function getActiveDomainHost(): string
    {
        if ($this->activeDomainHost === null) {
            $cached = $this->loadCacheValue(self::CACHE_KEY_ACTIVE_DOMAIN);
            $this->activeDomainHost = match (true) {
                is_string($cached) && in_array($cached, self::DOMAINS, true) === true => $cached,
                default => self::DOMAINS[0],
            };
        }

        return $this->activeDomainHost;
    }

    private function setActiveDomainHost(string $host): void
    {
        $this->activeDomainHost = $host;
        $this->saveCacheValue(self::CACHE_KEY_ACTIVE_DOMAIN, $host, self::CACHE_TIMEOUT);
    }

    private function baseURI(): string
    {
        return 'https://' . $this->getActiveDomainHost() . '/';
    }

    private function getFileDomain(): string
    {
        return 'https://file.' . $this->getActiveDomainHost();
    }

    private function getThumbnailDomain(): string
    {
        return 'https://img.' . $this->getActiveDomainHost();
    }

    private function getFileUrl(string $path, ?string $filename = null): string
    {
        $url = $this->getFileDomain() . '/data' . $path;

        return $filename !== null ? $url . '?f=' . urlencode($filename) : $url;
    }

    private function getThumbnailUrl(string $path): string
    {
        return $this->getThumbnailDomain() . '/thumbnail/data' . $path;
    }

    private function getAvatarUrl(string $service, string $userId): string
    {
        return $this->baseURI() . 'icons/' . $service . '/' . $userId;
    }

    private function normalizeUrls(string $content): string
    {
        $activeHost = $this->getActiveDomainHost();

        foreach (self::DOMAINS as $host) {
            if ($host !== $activeHost) {
                $content = str_replace('https://file.' . $host, 'https://file.' . $activeHost, $content);
                $content = str_replace('https://img.' . $host, 'https://img.' . $activeHost, $content);
                $content = str_replace('https://' . $host, 'https://' . $activeHost, $content);
            }
        }

        return $content;
    }

    private function resolveRelativeUrls(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $base = rtrim($this->baseURI(), '/');
        $fileBase = $this->getFileDomain();

        $html = (string)preg_replace('/\b(src|href)="(\/data\/[^"]+)"/i', '$1="' . $fileBase . '$2"', $html);
        $html = (string)preg_replace('/\b(src|href)="(\/(?!\/)[^"]+)"/i', '$1="' . $base . '$2"', $html);

        return $html;
    }

    private function getMimeType(string $filename): string
    {
        $ext = $this->getExtension($filename);

        return $this->mimeCache[$ext] ??= self::MIME_TYPES[$ext] ?? 'application/octet-stream';
    }

    private function getExtension(string $filename): string
    {
        $cleanFilename = (string)preg_replace('/[^\x20-\x7E]/', '', $filename);
        $cleanFilename = trim($cleanFilename);

        return strtolower(trim(pathinfo($cleanFilename, PATHINFO_EXTENSION)));
    }

    private function isImageByExtension(string $filename): bool
    {
        return in_array($this->getExtension($filename), self::IMAGE_EXTENSIONS, true);
    }

    private function isVideoByExtension(string $filename): bool
    {
        return in_array($this->getExtension($filename), self::VIDEO_EXTENSIONS, true);
    }

    private function isMediaByExtension(string $filename): bool
    {
        return match (true) {
            $this->isImageByExtension($filename), $this->isVideoByExtension($filename) => true,
            default => false,
        };
    }

    private function hasMedia(array $post): bool
    {
        if (empty($post['file']['path']) === false) {
            $name = $post['file']['name'] ?? basename($post['file']['path']);

            if ($this->isMediaByExtension((string)$name) === true) {
                return true;
            }
        }

        if (empty($post['attachments']) === false && is_array($post['attachments']) === true) {
            foreach ($post['attachments'] as $file) {
                if (empty($file['path']) === false) {
                    $name = $file['name'] ?? basename($file['path']);

                    if ($this->isMediaByExtension((string)$name) === true) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function cleanUnicodeCharacters(string $text): string
    {
        $text = (string)preg_replace_callback(
            '/[\x{10000}-\x{10FFFF}]/u',
            fn() => '',
            $text
        );

        return (string)preg_replace(
            ['/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '/[\x{FFFE}\x{FEFF}]/u'],
            '',
            $text
        );
    }

    private function formatUrlsInText(string $text): string
    {
        $parts = preg_split('/(<[^>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inAnchor = false;
        $result = '';
        $style = self::CSS['url-link'];

        foreach ($parts as $part) {
            if (preg_match('/^<a\b/i', $part) === 1) {
                $inAnchor = true;
            } elseif (preg_match('/^<\/a>$/i', $part) === 1) {
                $inAnchor = false;
            } elseif ($inAnchor === false && trim($part) !== '' && str_starts_with(ltrim($part), '<') === false) {
                $part = (string)preg_replace_callback(
                    '/(https?:\/\/[^\s<>\"]+)/i',
                    fn(array $matches): string => sprintf(
                        '<a href="%s" style="%s">%s</a>',
                        htmlspecialchars($matches[1], ENT_QUOTES | ENT_HTML5),
                        $style,
                        htmlspecialchars($matches[1], ENT_QUOTES | ENT_HTML5)
                    ),
                    $part
                );
            }

            $result .= $part;
        }

        return $result;
    }

    private function sanitizeHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = $this->cleanUnicodeCharacters($html);

        $dom = sanitize(
            $html,
            self::SANITIZE_TAGS_TO_REMOVE,
            self::SANITIZE_ATTRIBUTES_TO_KEEP,
            []
        );

        $html = trim((string)($dom->innertext ?? ''));

        $replacements = [
            '/<p>\s*<\/p>/i' => '',
            '/<div>\s*<\/div>/i' => '',
            '/<span>\s*<\/span>/i' => '',
            '/(<br\s*\/?>\s*){3,}/i' => '<br><br>',
            '/&nbsp;/i' => ' ',
        ];

        return trim((string)preg_replace(array_keys($replacements), array_values($replacements), $html));
    }

    private function sanitizeText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = $this->cleanUnicodeCharacters($text);

        $replacements = [
            '/\h+/' => ' ',
            '/^\s*\n/m' => '',
            '/\n{3,}/' => "\n\n",
        ];

        return trim((string)preg_replace(array_keys($replacements), array_values($replacements), $text));
    }

    private function renderImage(string $url, string $alt): string
    {
        return sprintf(
            '<img src="%s" alt="%s" style="%s">',
            htmlspecialchars($url, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5),
            self::CSS['image']
        );
    }

    private function renderVideo(string $url, string $mimeType): string
    {
        return sprintf(
            '<video controls style="%s"><source src="%s" type="%s">Your browser does not support the video tag.</video>',
            self::CSS['video'],
            htmlspecialchars($url, ENT_QUOTES | ENT_HTML5),
            $mimeType
        );
    }

    private function renderExternalLink(string $url): string
    {
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);

        return sprintf(
            '<div style="%s"><strong>External Link:</strong><br><a href="%s" style="%s">%s</a></div>',
            self::CSS['external-link-container'],
            $escapedUrl,
            self::CSS['url-link'],
            $escapedUrl
        );
    }

    private function getJson(string $endpoint): array
    {
        $service = $this->getInput('service');
        $curlOptions = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
        ];

        $activeHost = $this->getActiveDomainHost();
        $hostsToTry = [$activeHost];

        foreach (self::DOMAINS as $host) {
            if ($host !== $activeHost) {
                $hostsToTry[] = $host;
            }
        }

        $lastException = null;

        foreach ($hostsToTry as $host) {
            $url = 'https://' . $host . '/' . self::API_PREFIX . $service . $endpoint;

            try {
                $apiResponse = getContents($url, [], $curlOptions);

                if (is_string($apiResponse) === false) {
                    $lastException = new \Exception(
                        sprintf('getContents() returned non-string (%s) from %s', get_debug_type($apiResponse), $host)
                    );
                    continue;
                }

                if (json_validate($apiResponse) === false) {
                    $lastException = new \Exception(
                        sprintf('Invalid JSON response from %s', $host)
                    );
                    continue;
                }

                $data = \Json::decode($apiResponse);

                if (is_array($data) === false) {
                    $lastException = new \Exception(
                        sprintf('Unexpected JSON type from %s: %s', $host, get_debug_type($data))
                    );
                    continue;
                }

                if (isset($data['error']) === true) {
                    $lastException = new \Exception(
                        sprintf('API error from %s: %s', $host, (string)$data['error'])
                    );
                    continue;
                }

                $this->setActiveDomainHost($host);

                return $data;
            } catch (\Exception $e) {
                $lastException = $e;
                $this->logger->warning(sprintf(
                    'Pawchive mirror %s failed for %s: %s',
                    $host,
                    $endpoint,
                    $e->getMessage()
                ));
                continue;
            }
        }

        throwServerException(sprintf(
            'All Pawchive mirrors failed. Last error: %s',
            ($lastException !== null) === true ? $lastException->getMessage() : 'unknown'
        ));
    }

    private function collectFiles(array $post): array
    {
        $files = [];
        $seenPaths = [];

        if (empty($post['file']['path']) === false) {
            $file = $post['file'];
            $file['name'] = isset($file['name']) === true ? trim((string)preg_replace('/[^\x20-\x7E]/', '', (string)$file['name'])) : null;
            $file['path'] = trim((string)preg_replace('/[^\x20-\x7E]/', '', (string)$file['path']));
            $seenPaths[$file['path']] = true;
            $files[] = $file;
        }

        if (empty($post['attachments']) === false && is_array($post['attachments']) === true) {
            foreach ($post['attachments'] as $file) {
                if (empty($file['path']) === false) {
                    $file['name'] = isset($file['name']) === true ? trim((string)preg_replace('/[^\x20-\x7E]/', '', (string)$file['name'])) : null;
                    $file['path'] = trim((string)preg_replace('/[^\x20-\x7E]/', '', (string)$file['path']));

                    if (isset($seenPaths[$file['path']]) === true) {
                        continue;
                    }

                    $seenPaths[$file['path']] = true;
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function thumbnailsAvailable(array $files): bool
    {
        return array_any($files, function (array $file): bool {
            $fileName = trim($file['name'] ?? basename($file['path']));

            if ($fileName === '' || $this->isImageByExtension($fileName) === false) {
                return false;
            }

            $ch = curl_init($this->getThumbnailUrl($file['path']));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_RANGE => 'bytes=0-0',
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return $code >= 200 && $code < 400;
        });
    }

    private function processFiles(array $files, bool $hideAttachments, string &$contentHtml, array &$downloadLinks): void
    {
        $containerStyle = self::CSS['file-container'];
        $useThumbnails = $this->thumbnailsAvailable($files);

        foreach ($files as $file) {
            $fileName = $file['name'] ?? basename($file['path']);
            $fileName = trim($fileName);

            if ($fileName === '') {
                continue;
            }

            $fullUrl = $this->getFileUrl($file['path'], $fileName);

            $fileType = match (true) {
                $this->isImageByExtension($fileName) === true => 'image',
                $this->isVideoByExtension($fileName) === true => 'video',
                default => 'file',
            };

            if (($fileType === 'image') === true) {
                $imageUrl = $useThumbnails === true ? $this->getThumbnailUrl($file['path']) : $fullUrl;

                $contentHtml .= sprintf(
                    '<div style="%s">%s</div>',
                    $containerStyle,
                    $this->renderImage($imageUrl, $fileName)
                );

                if ($hideAttachments === false) {
                    $downloadLinks[] = ['url' => $fullUrl, 'name' => $fileName];
                }

                continue;
            }

            if (($fileType === 'video') === true) {
                if ($hideAttachments === false) {
                    $contentHtml .= sprintf(
                        '<div style="%s">%s</div>',
                        $containerStyle,
                        $this->renderVideo($fullUrl, $this->getMimeType($fileName))
                    );

                    $downloadLinks[] = ['url' => $fullUrl, 'name' => $fileName];
                }

                continue;
            }

            if ($hideAttachments === false) {
                $downloadLinks[] = ['url' => $fullUrl, 'name' => $fileName];
            }
        }
    }

    private function renderAttachmentsBlock(array $downloadLinks): string
    {
        if (empty($downloadLinks) === true) {
            return '';
        }

        $itemsHtml = '';
        $linkStyle = self::CSS['file-link'];
        $itemStyle = self::CSS['attachments-item'];

        foreach ($downloadLinks as $link) {
            $itemsHtml .= sprintf(
                '<li style="%s"><a href="%s" style="%s" download>%s</a></li>',
                $itemStyle,
                htmlspecialchars($link['url'], ENT_QUOTES | ENT_HTML5),
                $linkStyle,
                htmlspecialchars($link['name'], ENT_QUOTES | ENT_HTML5)
            );
        }

        return sprintf(
            '<div style="%s"><h4 style="%s">Attachments</h4><ul style="%s">%s</ul></div>',
            self::CSS['attachments-container'],
            self::CSS['attachments-heading'],
            self::CSS['attachments-list'],
            $itemsHtml
        );
    }

    public function getIcon(): string
    {
        $icon = $this->authorAvatarUrl ?? parent::getIcon();

        return $this->normalizeUrls($icon);
    }

    public function getURI(): string
    {
        $service = $this->getInput('service');
        $user = $this->getInput('user');
        $uri = $this->baseURI() . $service . '/user/' . $user;

        return $this->normalizeUrls($uri);
    }

    public function getName(): string
    {
        return $this->author ?? parent::getName();
    }

    public function collectData(): void
    {
        $service = $this->getInput('service');
        $userId = (string)$this->getInput('user');
        $userPath = '/user/' . $userId;

        $profile = $this->getJson("{$userPath}/profile");
        $this->author = $profile['name'] ?? 'Unknown';
        $this->authorAvatarUrl = $this->getAvatarUrl($service, $userId);

        $queryParams = [];
        $q = $this->getInput('q');

        if ($q !== null && $q !== '') {
            $queryParams['q'] = $q;
        }

        $queryString = $queryParams !== [] ? '?' . http_build_query($queryParams) : '';

        $json = $this->getJson("{$userPath}{$queryString}");

        $hideAttachments = (bool)$this->getInput('hide_videos');
        $hideEmpty = (bool)$this->getInput('hide_empty');
        $limit = $this->getInput('limit');

        $count = 0;

        foreach ($json as $post) {
            if ($hideEmpty === true && $this->hasMedia($post) === false) {
                continue;
            }

            $this->items[] = $this->createItem($post, $hideAttachments);
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }
    }

    private function createItem(array $post, bool $hideAttachments): array
    {
        $content = ($post['content'] ?? '')
            |> $this->normalizeUrls(...)
            |> $this->resolveRelativeUrls(...)
            |> $this->sanitizeHtml(...);

        $files = $this->collectFiles($post);

        foreach ($files as $file) {
            $fileName = $file['name'] ?? basename($file['path']);
            $fileName = trim($fileName);

            if ($fileName !== '') {
                $content = (string)preg_replace(
                    '/(?<![a-zA-Z0-9])' . preg_quote($fileName, '/') . '(?![a-zA-Z0-9])/i',
                    '',
                    $content
                );
            }
        }

        $content = $this->formatUrlsInText($content);

        $timestamp = null;
        $dateStr = $post['published'] ?? $post['added'] ?? null;

        if ($dateStr !== null) {
            $parsed = strtotime((string)$dateStr);

            if ($parsed !== false) {
                $timestamp = $parsed;
            }
        }

        $item = [
            'author' => $this->author,
            'content' => $content,
            'timestamp' => $timestamp,
            'title' => $this->sanitizeText($post['title'] ?? 'Post ' . ($post['id'] ?? '?')),
            'uid' => (string)($post['id'] ?? uniqid('pawchive_', true)),
            'uri' => $this->getURI() . '/post/' . ($post['id'] ?? ''),
        ];

        $contentHtml = $item['content'];

        if (empty($post['embed']['url']) === false) {
            $contentHtml .= $this->renderExternalLink($this->normalizeUrls((string)$post['embed']['url']));
        }

        $downloadLinks = [];
        $this->processFiles($files, $hideAttachments, $contentHtml, $downloadLinks);
        $contentHtml .= $this->renderAttachmentsBlock($downloadLinks);

        $item['content'] = $contentHtml;

        return $item;
    }

    public function getItems(): array
    {
        $items = parent::getItems();

        foreach ($items as &$item) {
            if (isset($item['content']) === true) {
                $item['content'] = $this->normalizeUrls($item['content']);
            }

            if (isset($item['uri']) === true) {
                $item['uri'] = $this->normalizeUrls($item['uri']);
            }
        }

        unset($item);

        return $items;
    }
}
