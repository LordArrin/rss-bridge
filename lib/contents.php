<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Http\Response;
use RSSBridge\Utils\Json;
use RSSBridge\Proxies\DirectProxy;
use RSSBridge\Proxies\ProxyFactory;

/**
 * @return list<array<string, mixed>>
 */
function get_sitemap(string $url): array
{
    $xmlString = getContents($url);
    if ($xmlString === '') {
        return [];
    }

    try {
        $doc = \Dom\XMLDocument::createFromString($xmlString, LIBXML_NOERROR | LIBXML_NOWARNING);
    } catch (\Throwable $e) {
        throwServerException('Failed to parse sitemap: ' . $e->getMessage());
    }

    $urls = [];
    foreach ($doc->querySelectorAll('url') as $urlNode) {
        $locNode = $urlNode->querySelector('loc');
        $lastmodNode = $urlNode->querySelector('lastmod');
        $changefreqNode = $urlNode->querySelector('changefreq');
        $priorityNode = $urlNode->querySelector('priority');

        $item = [
            'loc'        => $locNode !== null ? $locNode->textContent : null,
            'lastmod'    => $lastmodNode !== null ? $lastmodNode->textContent : null,
            'changefreq' => $changefreqNode !== null ? $changefreqNode->textContent : null,
            'priority'   => $priorityNode !== null ? $priorityNode->textContent : null,
        ];

        $newsNodes = $urlNode->getElementsByTagNameNS('http://www.google.com/schemas/sitemap-news/0.9', '*');
        foreach ($newsNodes as $element) {
            $localName = $element->localName;
            $prefix = $element->prefix ?? 'news';
            if (isset($item[$prefix]) === false || is_array($item[$prefix]) === false) {
                $item[$prefix] = [];
            }
            /** @var array<string, string> $newsData */
            $newsData = $item[$prefix];
            $newsData[$localName] = $element->textContent;
            $item[$prefix] = $newsData;
        }
        $urls[] = $item;
    }
    return $urls;
}

/**
 * Computes the unix timestamp until which a cached upstream response may be
 * served without contacting the origin.
 *
 * Priority (RFC 9111 section 4.2):
 *   1. Cache-Control: no-store and no-cache forbid blind serving, and private
 *      forbids it for this shared cache; s-maxage wins over max-age otherwise.
 *   2. Expires header.
 *   3. Heuristic freshness (RFC 9111 section 4.2.2) for successful responses
 *      without explicit signals: 10% of the interval since Last-Modified,
 *      clamped to [60, 86400] seconds; a minimal 60-second freshness when no
 *      validator exists, so repeated calls to one URL within a collectData()
 *      run are deduplicated instead of refetched every time.
 *
 * must-revalidate is deliberately NOT a blocking directive here: per RFC 9111
 * it only forbids serving a STALE response without revalidation. Callers
 * compare the returned timestamp against time() and revalidate once it has
 * passed, so the directive is honoured without forcing a revalidation on
 * every call while the response is still fresh.
 *
 * Returns null when the response must always be revalidated.
 */
function responseFreshUntil(Response $response): ?int
{
    $cacheControl = $response->getHeader('cache-control');
    if (is_string($cacheControl) === true && $cacheControl !== '') {
        $directives = array_map('trim', explode(',', strtolower($cacheControl)));
        foreach (['no-store', 'no-cache', 'private'] as $blocking) {
            if (in_array($blocking, $directives, true) === true) {
                return null;
            }
        }

        $maxAge = null;
        $sMaxAge = null;
        foreach ($directives as $directive) {
            if (str_starts_with($directive, 'max-age=') === true) {
                $maxAge = (int) trim(substr($directive, strlen('max-age=')), '"');
            } elseif (str_starts_with($directive, 's-maxage=') === true) {
                $sMaxAge = (int) trim(substr($directive, strlen('s-maxage=')), '"');
            }
        }

        $seconds = $sMaxAge ?? $maxAge;
        if ($seconds !== null) {
            $date = $response->getHeader('date');
            $anchor = is_string($date) === true ? strtotime($date) : false;
            if ($anchor === false) {
                $anchor = time();
            }
            $age = $response->getHeader('age');
            if (is_string($age) === true && $age !== '' && ctype_digit($age) === true) {
                $anchor -= (int) $age;
            }
            return $anchor + max(0, $seconds);
        }
    }

    $expires = $response->getHeader('expires');
    if (is_string($expires) === true && $expires !== '') {
        $timestamp = strtotime($expires);
        if ($timestamp === false) {
            return time() - 1;
        }
        return $timestamp;
    }

    if (in_array($response->getCode(), [200, 201, 202], true) === false) {
        return null;
    }

    $date = $response->getHeader('date');
    $anchor = is_string($date) === true ? strtotime($date) : false;
    if ($anchor === false) {
        $anchor = time();
    }
    $age = $response->getHeader('age');
    if (is_string($age) === true && $age !== '' && ctype_digit($age) === true) {
        $anchor -= (int) $age;
    }

    $lastModified = $response->getHeader('last-modified');
    if (is_string($lastModified) === true && $lastModified !== '') {
        $lastModifiedTimestamp = strtotime($lastModified);
        if ($lastModifiedTimestamp !== false && $lastModifiedTimestamp <= $anchor) {
            $heuristic = (int) (($anchor - $lastModifiedTimestamp) * 0.1);
            return $anchor + max(60, min(86400, $heuristic));
        }
    }

    return $anchor + 60;
}

/**
 * Fetch data from an http url, with a shared upstream response cache.
 *
 * Storage lifetime and freshness lifetime are separate concerns: a 200/304
 * response is stored for 86400 seconds so its validators stay available for
 * conditional requests, while responseFreshUntil() decides on every read
 * whether the stored body may still be served without contacting the origin.
 *
 * The cache key is a hash of the full identity tuple (url, body, headers,
 * identity-defining cURL options). Hashing the encoded tuple instead of
 * joining its parts with a separator removes any ambiguity between a URL
 * that contains separators and the hash fields that follow it.
 *
 * @template T of bool
 * @param array<string, string>|list<string> $httpHeaders E.g. ['Accept' => 'application/json'] or ['Accept: application/json']
 * @param array<int, mixed> $curlOptions Associative array e.g. [CURLOPT_MAXREDIRS => 3]
 * @param T $returnFull
 * @return (T is true ? Response : string)
 */
function getContents(
    string $url,
    array $httpHeaders = [],
    array $curlOptions = [],
    bool $returnFull = false
): string|Response {
    global $container;

    /** @var \RSSBridge\Http\HttpClient $httpClient */
    $httpClient = $container['http_client'];

    /** @var CacheInterface $cache */
    $cache = $container['cache'];

    $timeoutRaw = getenv('HTTP_TIMEOUT');
    $timeoutValue = ($timeoutRaw !== false && $timeoutRaw !== '' && (int) $timeoutRaw > 0) ? (int) $timeoutRaw : 60;
    $retriesRaw = getenv('HTTP_RETRIES');
    $retriesValue = ($retriesRaw !== false && $retriesRaw !== '' && (int) $retriesRaw >= 0) ? (int) $retriesRaw : 2;

    $config = [
        'timeout'      => $timeoutValue,
        'retries'      => $retriesValue,
        'curl_options' => $curlOptions + [CURLOPT_ENCODING => ''],
    ];

    $httpHeadersNormalized = [];
    foreach ($httpHeaders as $key => $value) {
        if (is_string($key) === true) {
            $httpHeadersNormalized[$key] = $value;
        } else {
            $parts = explode(':', $value, 2);
            if (count($parts) === 2) {
                $httpHeadersNormalized[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    $keyHeaders = [];
    foreach ($httpHeadersNormalized as $name => $value) {
        $keyHeaders[strtolower($name)] = trim($value);
    }
    ksort($keyHeaders);

    $headersHash = null;
    if ($keyHeaders !== []) {
        try {
            $headersHash = md5(Json::encode($keyHeaders, false));
        } catch (\JsonException $e) {
            $headersHash = md5(serialize($keyHeaders));
        }
    }

    $requestBodyHash = null;
    if (isset($curlOptions[CURLOPT_POSTFIELDS]) === true) {
        try {
            $requestBodyHash = md5(Json::encode($curlOptions[CURLOPT_POSTFIELDS], false));
        } catch (\JsonException $e) {
            $requestBodyHash = md5(serialize($curlOptions[CURLOPT_POSTFIELDS]));
        }
    }

    $identityOptions = [
        CURLOPT_USERAGENT,
        CURLOPT_COOKIE,
        CURLOPT_COOKIEFILE,
        CURLOPT_REFERER,
        CURLOPT_CUSTOMREQUEST,
        CURLOPT_POST,
        CURLOPT_HTTPHEADER,
        CURLOPT_PROXY,
    ];
    $keyOptions = [];
    foreach ($identityOptions as $option) {
        if (array_key_exists($option, $curlOptions) === true) {
            $keyOptions[$option] = $curlOptions[$option];
        }
    }
    $optionsHash = null;
    if ($keyOptions !== []) {
        try {
            $optionsHash = md5(Json::encode($keyOptions, false));
        } catch (\JsonException $e) {
            $optionsHash = md5(serialize($keyOptions));
        }
    }

    $cacheKeyParts = ['server', $url, $requestBodyHash];
    if ($headersHash !== null) {
        $cacheKeyParts[] = $headersHash;
    }
    if ($optionsHash !== null) {
        $cacheKeyParts[] = $optionsHash;
    }

    try {
        $cacheKey = 'server_' . md5(Json::encode($cacheKeyParts, false));
    } catch (\JsonException $e) {
        $cacheKey = 'server_' . md5(serialize($cacheKeyParts));
    }

    $cachedResponse = $cache->get($cacheKey);
    if (($cachedResponse instanceof Response) === false) {
        $cachedResponse = null;
    }

    if ($cachedResponse !== null) {
        $freshUntil = responseFreshUntil($cachedResponse);
        if ($freshUntil !== null && $freshUntil > time()) {
            if ($returnFull === true) {
                return $cachedResponse;
            }
            return $cachedResponse->getBody();
        }

        $etag = $cachedResponse->getHeader('etag');
        if (is_string($etag) === true && $etag !== '') {
            $httpHeadersNormalized['if-none-match'] = $etag;
        } else {
            $lastModified = $cachedResponse->getHeader('last-modified');
            if (is_string($lastModified) === true && $lastModified !== '') {
                $httpHeadersNormalized['if-modified-since'] = $lastModified;
            }
        }
    }

    $config['headers'] = $httpHeadersNormalized;

    $maxFileSize = getenv('HTTP_MAX_FILESIZE');
    if ($maxFileSize !== false && $maxFileSize !== '' && (int)$maxFileSize > 0) {
        $config['max_filesize'] = (int)$maxFileSize * 2 ** 20;
    }

    $response = $httpClient->request($url, $config);

    if ($response->getCode() === 304 && $cachedResponse === null) {
        foreach (['if-none-match', 'if-modified-since'] as $staleValidator) {
            unset($httpHeadersNormalized[$staleValidator]);
        }
        $config['headers'] = $httpHeadersNormalized;
        $response = $httpClient->request($url, $config);
    }

    switch ($response->getCode()) {
        case 200:
        case 201:
        case 202:
            // curl transparently decodes the body (CURLOPT_ENCODING), so the
            // upstream Content-Encoding/Content-Length no longer describe the
            // stored representation. Strip them so cached and revalidated
            // responses stay truthful (the 304 merge below does the same).
            $response = $response
            ->withoutHeader('content-encoding')
            ->withoutHeader('content-length');
            $cacheControl = $response->getHeader('cache-control');
            $directives = [];
            if (is_string($cacheControl) === true && $cacheControl !== '') {
                $directives = array_map('trim', explode(',', strtolower($cacheControl)));
            }
            if (in_array('no-store', $directives, true) === true || in_array('private', $directives, true) === true) {
                $cache->delete($cacheKey);
                break;
            }
            $cache->set($cacheKey, $response, 86400);
            break;
        case 301:
        case 302:
        case 303:
        case 307:
        case 308:
            break;
        case 304:
            if ($cachedResponse === null) {
                throw new \HttpException(sprintf(
                    '%s returned 304 Not Modified but no stored response is available to revalidate',
                    $url
                ), 502);
            }

            $cacheControl = $response->getHeader('cache-control');
            $directives = [];
            if (is_string($cacheControl) === true && $cacheControl !== '') {
                $directives = array_map('trim', explode(',', strtolower($cacheControl)));
            }

            $mergedHeaders = $cachedResponse->getHeaders();
            foreach ($response->getHeaders() as $name => $values) {
                $mergedHeaders[$name] = $values;
            }
            unset($mergedHeaders['content-length'], $mergedHeaders['content-encoding']);

            if (isset($mergedHeaders['date']) === false) {
                $mergedHeaders['date'] = [gmdate('D, d M Y H:i:s \G\M\T')];
            }

            $merged = new Response(
                $cachedResponse->getBody(),
                200,
                $mergedHeaders
            );

            if (in_array('no-store', $directives, true) === true || in_array('private', $directives, true) === true) {
                $cache->delete($cacheKey);
            } else {
                $cache->set($cacheKey, $merged, 86400);
            }
            $response = $merged;
            break;
        default:
            $e = \HttpException::fromResponse($response, $url);
            throw $e;
    }

    if ($returnFull === true) {
        return $response;
    }
    return $response->getBody();
}

function parseHtmlDocument(string $html, ?string $encoding = null): \Dom\HTMLDocument
{
    if ($encoding === '') {
        $encoding = null;
    }
    try {
        return \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, $encoding);
    } catch (\Throwable) {
        $clean = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', '', $html);
        $clean = is_string($clean) === true ? $clean : '';
        if (preg_match('//u', $clean) === 1) {
            return \Dom\HTMLDocument::createFromString($clean, LIBXML_NOERROR, 'UTF-8');
        }
        return \Dom\HTMLDocument::createFromString($clean, LIBXML_NOERROR);
    }
}

function responseCharset(Response $response): ?string
{
    $contentType = $response->getHeader('content-type');
    if (is_string($contentType) === false || $contentType === '') {
        return null;
    }
    if (preg_match('/charset\s*=\s*"?([a-z0-9_\-:.]+)"?/i', $contentType, $matches) !== 1) {
        return null;
    }
    return strtolower($matches[1]);
}

function parseHtmlBody(string $html, ?string $charset = null): \Dom\HTMLDocument
{
    if (preg_match('//u', $html) === 1) {
        return parseHtmlDocument($html, 'UTF-8');
    }
    if (str_starts_with($html, "\xFF\xFE") === true || str_starts_with($html, "\xFE\xFF") === true) {
        return parseHtmlDocument($html);
    }
    if ($charset !== null && $charset !== '') {
        return parseHtmlDocument($html, $charset);
    }
    return parseHtmlDocument($html);
}

function parseHtmlFragment(string $html, string $wrapperId = 'rss-bridge-temp-wrapper'): ?\Dom\HTMLDocument
{
    if (trim($html) === '') {
        return null;
    }
    try {
        return parseHtmlDocument(sprintf('<div id="%s">%s</div>', $wrapperId, $html));
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Gets contents from the Internet as HTMLDocument object.
 *
 * @param array<string, string>|list<string> $header
 * @param array<int, mixed> $opts
 * @return \Dom\HTMLDocument Parsed HTML document
 * @throws \Exception If the HTTP response is empty or parsing fails
 */
function getSimpleHTMLDOM(
    string $url,
    array $header = [],
    array $opts = []
): \Dom\HTMLDocument {
    $response = getContents($url, $header, $opts, true);
    $html = $response->getBody();
    if ($html === '') {
        throw new \Exception('Unable to parse dom because the http response was the empty string');
    }

    return parseHtmlBody($html, responseCharset($response));
}

/**
 * Retrieves HTML via a named proxy profile.
 *
 * Configuration failures surface as 500 (server misconfiguration). Transient
 * fetch failures surface as ServiceUnavailableException, which
 * httpStatusFromException() maps to a per-feed 502 while its retryAfter hint
 * sizes the server-side backoff window. No 429/503 and no Retry-After ever
 * reach the reader: FreshRSS would turn those into a domain-wide cooldown.
 * Already-typed HttpExceptions pass through with their own status.
 *
 * @param array<string, mixed> $options
 * @return string HTML content
 */
function getProtectedContents(string $url, string $profileName, array $options = []): string
{
    try {
        $proxy = ProxyFactory::safeFromProfile($profileName);
    } catch (\Throwable $e) {
        throwServerException(sprintf(
            "Proxy profile '%s' is not available: %s\n\nConfigure it via PROXY_PROFILE_%s_* environment variables.",
            $profileName,
            $e->getMessage(),
            strtoupper($profileName)
        ));
    }

    try {
        return $proxy->getHtml($url, $options);
    } catch (\HttpException $e) {
        throw $e;
    } catch (\Throwable $e) {
        if ($proxy instanceof DirectProxy) {
            throwServiceUnavailableException(sprintf(
                "Failed to fetch %s via DirectProxy: %s\n\nIf this site is protected by Cloudflare, configure a FlareSolverr proxy via environment variables:\n\n  PROXY_PROFILE_FLARESOLVERR_URL=http://localhost:8191",
                $url,
                $e->getMessage()
            ), 600);
        }
        throwServiceUnavailableException(sprintf(
            "Proxy profile '%s' failed to fetch %s: %s",
            $profileName,
            $url,
            $e->getMessage()
        ), 60);
    }
}

/**
 * Retrieves HTML via a named proxy profile as HTMLDocument object.
 *
 * @param array<string, mixed> $options
 * @return \Dom\HTMLDocument Parsed HTML document
 * @throws \Exception If the proxy returns empty HTML or parsing fails
 */
function getProtectedSimpleHTMLDOM(string $url, string $profileName, array $options = []): \Dom\HTMLDocument
{
    $html = getProtectedContents($url, $profileName, $options);

    if ($html === '') {
        throwServiceUnavailableException(sprintf("Proxy profile '%s' returned empty HTML for: %s", $profileName, $url), 60);
    }

    return parseHtmlBody($html);
}

/**
 * Retrieves binary content (images, videos, etc.) via a named proxy profile.
 *
 * @param array<string, mixed> $options
 * @return array{body: string, type: string}|null
 */
function getProtectedBinary(string $url, string $profileName, array $options = []): ?array
{
    try {
        $proxy = ProxyFactory::safeFromProfile($profileName);
    } catch (\Throwable $e) {
        global $container;
        if (isset($container['logger']) === true) {
            $container['logger']->error(sprintf("Failed to load proxy profile '%s': %s", $profileName, $e->getMessage()));
        }
        return null;
    }

    try {
        return $proxy->getBinary($url, $options);
    } catch (\Throwable $e) {
        global $container;
        if (isset($container['logger']) === true) {
            $container['logger']->warning(sprintf(
                'Proxy [%s] failed to fetch binary %s: %s',
                $proxy->getName(),
                $url,
                $e->getMessage()
            ));
        }
        return null;
    }
}
