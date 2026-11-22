<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Http\Response;

use function urljoin;

final class BastaBridge extends BridgeAbstract
{
    public const NAME = 'Bastamag';
    public const URI = 'https://basta.media/';
    public const DESCRIPTION = 'Returns the newest articles.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'defaultValue' => 10,
            'title' => 'Number of articles to fetch'
        ]
    ]];

    private const ANTI_BOT_COOKIE_NAME = 'ngacookie';
    private const ANTI_BOT_FALLBACK_VALUE = 'miamv2';
    private const ANTI_BOT_COOKIE_TTL = 604800;
    private const ANTI_BOT_CACHE_KEY = 'basta_antibot_cookie_v1';

    public function collectData(): void
    {
        $limit = (int) $this->getInput('limit');
        if ($limit <= 0) {
            $limit = 10;
        }

        $feedUrl = self::URI . 'spip.php?page=backend';
        $feedHtml = $this->fetchWithAntiBotCookie($feedUrl);

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = \Dom\XMLDocument::createFromString($feedHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (\Throwable $e) {
            throwServerException("Upstream feed is not well-formed XML: {$e->getMessage()}");
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $items = $xml->querySelectorAll('item');
        $count = 0;

        foreach ($items as $element) {
            if ($count >= $limit) {
                break;
            }

            $titleEl = $element->querySelector('title');
            $guidEl = $element->querySelector('guid');
            $dateEl = $element->querySelector('dc\:date');

            if ($titleEl === null || $guidEl === null) {
                continue;
            }

            $title = trim($titleEl->textContent ?? '');
            $uri = trim($guidEl->textContent ?? '');

            if ($title === '' || $uri === '') {
                continue;
            }

            $timestamp = null;
            if ($dateEl !== null) {
                $dateText = trim($dateEl->textContent ?? '');
                if ($dateText !== '') {
                    $parsed = strtotime($dateText);
                    if ($parsed !== false) {
                        $timestamp = $parsed;
                    }
                }
            }

            $articleData = $this->fetchArticleData($uri);

            $this->items[] = [
                'title'     => $title,
                'uri'       => $uri,
                'timestamp' => $timestamp,
                'author'    => $articleData['author'],
                'content'   => $articleData['content'],
            ];

            $count++;
        }
    }

    /**
     * @return array{content: string, author: string}
     */
    private function fetchArticleData(string $uri): array
    {
        $html = $this->fetchWithAntiBotCookie($uri);

        $dom = parseHtmlDocument($html);

        $author = '';
        $authorBlock = $dom->querySelector('.mb-1.c-cartouche-authors');
        if ($authorBlock !== null) {
            $author = trim($authorBlock->textContent ?? '');
        }

        $content = $dom->querySelector('.o-main-content.o-content');
        if ($content === null) {
            $content = $dom->querySelector('div.texte');
        }
        if ($content === null) {
            return ['content' => '', 'author' => $author];
        }

        $this->removeJunk($content);
        $this->normalizeMedia($content);
        $this->resolveRelativeUrls($content);

        return [
            'content' => $content->innerHTML,
            'author' => $author,
        ];
    }

    private function fetchWithAntiBotCookie(string $url): string
    {
        $cookieValue = $this->getAntiBotCookieValue();
        $cookieHeader = self::ANTI_BOT_COOKIE_NAME . '=' . $cookieValue;

        $html = getContents($url, ['Cookie' => $cookieHeader]);
        if ($html === '') {
            throwServerException("Failed to fetch {$url}");
        }

        if ($this->isAntiBotPage($html) === true) {
            global $container;
            $cache = $container['cache'];
            $cache->delete(self::ANTI_BOT_CACHE_KEY);

            $freshValue = $this->getAntiBotCookieValue();
            $freshCookieHeader = self::ANTI_BOT_COOKIE_NAME . '=' . $freshValue;

            try {
                $httpClient = $container['http_client'];
                $response = $httpClient->request($url, [
                    'timeout' => 15,
                    'retries' => 0,
                    'headers' => ['Cookie' => $freshCookieHeader],
                ]);
                $html = $response->getBody();
            } catch (\Throwable $e) {
                throwServerException(
                    "Basta.media anti-bot page still returned after retry: {$e->getMessage()}"
                );
            }

            if ($this->isAntiBotPage($html) === true) {
                throwServerException(
                    "Basta.media anti-bot page still returned after retry. The cookie value may have changed; last tried: $freshValue"
                );
            }
        }

        return $html;
    }

    private function getAntiBotCookieValue(): string
    {
        global $container;
        $cache = $container['cache'];

        $cached = $cache->get(self::ANTI_BOT_CACHE_KEY);
        if (is_string($cached) === true && $cached !== '') {
            return $cached;
        }

        $value = self::ANTI_BOT_FALLBACK_VALUE;

        try {
            $httpClient = $container['http_client'];
            $response = $httpClient->request(self::URI, [
                'timeout' => 15,
                'retries' => 0,
            ]);
            $body = $response->getBody();

            if ($this->isAntiBotPage($body) === true) {
                $extracted = $this->extractCookieValue($body);
                if ($extracted !== null) {
                    $value = $extracted;
                }
            }
        } catch (\Throwable $e) {
        }

        $cache->set(self::ANTI_BOT_CACHE_KEY, $value, self::ANTI_BOT_COOKIE_TTL);
        return $value;
    }

    private function isAntiBotPage(string $html): bool
    {
        return str_contains($html, '<title>Overload</title>') === true
            && str_contains($html, self::ANTI_BOT_COOKIE_NAME) === true;
    }

    private function extractCookieValue(string $html): ?string
    {
        if (preg_match('/ngacookie=([a-zA-Z0-9_-]+)/', $html, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private function removeJunk(\Dom\Element $content): void
    {
        $junkSelectors = [
            '.o-content-footer',
            '.o-content-aside',
            'aside',
            'form',
            '.ajax-form-container',
            '.formulaire_spip',
            '.carto',
            'iframe',
            '.c-call--don',
            '.c-call--newsletter',
            '.c-call--modele',
            '.c-encart',
            '.c-encart--liens',
            '.c-encart--memesujet',
            '.c-encart--memesujet-logo',
            '.o-push',
            '.o-push--aside',
            '.sur-le-meme-sujet',
            '.meme-sujet',
            '.same-topic',
            '.lire-aussi',
            '.also-read',
            '.related',
            '.related-articles',
            '.articles-lies',
            '[data-role="related"]',
            'script',
            'style',
            'noscript',
            'svg',
            '.fb-like',
            '.twitter-tweet',
        ];

        foreach ($junkSelectors as $selector) {
            foreach (iterator_to_array($content->querySelectorAll($selector)) as $junk) {
                $junk->remove();
            }
        }
    }

    private function normalizeMedia(\Dom\Element $content): void
    {
        foreach (iterator_to_array($content->querySelectorAll('picture')) as $picture) {
            $parent = $picture->parentNode;
            if ($parent === null) {
                continue;
            }
            $img = $picture->querySelector('img');
            if ($img !== null) {
                $parent->insertBefore($img, $picture);
            }
            $picture->remove();
        }

        foreach ($content->querySelectorAll('img') as $img) {
            // 1. Restore the real image URL from lazy-loading attributes first
            $this->restoreLazyImage($img);

            // 2. Inline images from the target domain as Base64 to bypass anti-bot checks in feed readers
            $this->inlineImageWithCookie($img);

            // 3. Clean up unnecessary attributes
            foreach (['srcset', 'sizes', 'loading', 'onload', 'style', 'class', 'data-src', 'data-srcset'] as $attr) {
                if ($img->hasAttribute($attr) === true) {
                    $img->removeAttribute($attr);
                }
            }

            $img->setAttribute('style', 'max-width:100%;height:auto;');
        }
    }

    /**
     * Fetches an image with the anti-bot cookie, sanitizes it using the built-in
     * quirk, and inlines it as a Base64 Data URI. This prevents feed readers
     * from hitting the anti-bot page when requesting images directly.
     */
    private function inlineImageWithCookie(\Dom\Element $img): void
    {
        $src = $img->getAttribute('src') ?? '';
        if ($src === '' || str_starts_with($src, 'data:') === true) {
            return;
        }

        // Inline only images from the target domain to avoid slowing down external resources
        if (str_contains($src, 'basta.media') === false) {
            return;
        }

        $cookieValue = $this->getAntiBotCookieValue();
        $cookieHeader = self::ANTI_BOT_COOKIE_NAME . '=' . $cookieValue;

        try {
            /** @var Response $response */
            $response = getContents($src, ['Cookie' => $cookieHeader], [], true);

            $body = $response->getBody();
            $contentType = $response->getHeader('content-type') ?? '';

            // If the server returned the anti-bot HTML page instead of an image, abort inlining
            if (str_contains($body, '<title>Overload</title>') === true || str_starts_with($contentType, 'text/html') === true) {
                return;
            }

            // Determine MIME type
            $mime = 'image/jpeg'; // default fallback
            if (str_starts_with($contentType, 'image/') === true) {
                $mime = explode(';', $contentType)[0];
            } elseif (str_ends_with($src, '.png') === true) {
                $mime = 'image/png';
            } elseif (str_ends_with($src, '.webp') === true) {
                $mime = 'image/webp';
            }

            // Sanitize the image using the built-in quirk to strip EXIF metadata
            // and neutralize polyglot attacks before embedding.
            $sanitizedBody = sanitize_image($body);

            $img->setAttribute('src', 'data:' . $mime . ';base64,' . base64_encode($sanitizedBody));
        } catch (\Throwable $e) {
            // On failure, silently keep the original src URL as a fallback
        }
    }

    private function restoreLazyImage(\Dom\Element $img): void
    {
        $src = $img->getAttribute('src') ?? '';
        if ($src !== '' && str_starts_with($src, 'data:') === false) {
            return;
        }

        foreach (['data-src', 'data-original', 'data-lazy-src', 'data-cfsrc'] as $attr) {
            $candidate = $img->getAttribute($attr);
            if (
                $candidate !== null
                && $candidate !== ''
                && str_starts_with($candidate, 'data:') === false
            ) {
                $img->setAttribute('src', $candidate);
                return;
            }
        }

        foreach (['data-srcset', 'srcset'] as $attr) {
            $candidate = $img->getAttribute($attr);
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $url = self::bestSrcsetUrl($candidate);
            if ($url !== null) {
                $img->setAttribute('src', $url);
                return;
            }
        }
    }

    private static function bestSrcsetUrl(string $srcset): ?string
    {
        $best = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            if ($parts === false || $parts === [] || $parts[0] === '') {
                continue;
            }

            $url = $parts[0];
            if (str_starts_with($url, 'data:') === true) {
                continue;
            }

            $width = -1;
            if (isset($parts[1]) === true && preg_match('/^(\d+)w$/', $parts[1], $m) === 1) {
                $width = (int) $m[1];
            }

            if ($width >= $bestWidth) {
                $bestWidth = $width;
                $best = $url;
            }
        }

        return $best;
    }

    private function resolveRelativeUrls(\Dom\Element $container): void
    {
        $base = self::URI;
        $elements = $container->querySelectorAll('[src], [href], [poster]');

        foreach ($elements as $el) {
            foreach (['src', 'href', 'poster'] as $attr) {
                if ($el->hasAttribute($attr) === false) {
                    continue;
                }

                $value = $el->getAttribute($attr) ?? '';

                if (
                    $value === '' ||
                    preg_match('#^(https?:)?//#i', $value) === 1 ||
                    str_starts_with($value, 'data:') === true ||
                    str_starts_with($value, 'mailto:') === true ||
                    str_starts_with($value, 'javascript:') === true ||
                    str_starts_with($value, 'tel:') === true
                ) {
                    continue;
                }

                $resolvedUrl = urljoin($base, $value);
                if ($resolvedUrl !== null && $resolvedUrl !== '') {
                    $el->setAttribute($attr, $resolvedUrl);
                }
            }
        }
    }
}
