<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;
use RSSBridge\Proxies\DirectProxy;
use RSSBridge\Proxies\ProxyFactory;

function get_sitemap(string $url): array
{
    $doc = new \DOMDocument();
    $doc->loadXML(getContents($url));
    $urls = [];

    foreach ($doc->getElementsByTagName('url') as $url) {
        $item = [
            'loc'           => $url->getElementsByTagName('loc')->item(0)->nodeValue ?? null,
            'lastmod'       => $url->getElementsByTagName('lastmod')->item(0)->nodeValue ?? null,
            'changefreq'    => $url->getElementsByTagName('changefreq')->item(0)->nodeValue ?? null,
            'priority'      => $url->getElementsByTagName('priority')->item(0)->nodeValue ?? null,
        ];

        $news = $url->getElementsByTagNameNS('http://www.google.com/schemas/sitemap-news/0.9', '*');
        foreach ($news as $element) {
            $localName = $element->localName;
            $prefix = $element->prefix;
            $item[$prefix][$localName] = $element->nodeValue;
        }
        $urls[] = $item;
    }
    return $urls;
}

/**
 * Fetch data from an http url
 *
 * @param array $httpHeaders E.g. ['Content-type: text/plain']
 * @param array $curlOptions Associative array e.g. [CURLOPT_MAXREDIRS => 3]
 * @param bool $returnFull Whether to return Response object
 * @return string|Response
 */
function getContents(
    string $url,
    array $httpHeaders = [],
    array $curlOptions = [],
    bool $returnFull = false
) {
    global $container;

    /** @var HttpClient $httpClient */
    $httpClient = $container['http_client'];

    /** @var CacheInterface $cache */
    $cache = $container['cache'];

    // TODO: consider url validation at this point

    $config = [
        'useragent'     => Configuration::getConfig('http', 'useragent'),
        'timeout'       => Configuration::getConfig('http', 'timeout'),
        'retries'       => Configuration::getConfig('http', 'retries'),
        'curl_options'  => $curlOptions + [CURLOPT_ENCODING => ''],
    ];
    $httpHeadersNormalized = [];
    foreach ($httpHeaders as $httpHeader) {
        $parts = explode(':', $httpHeader);
        $headerName = trim($parts[0]);
        $headerValue = trim(implode(':', array_slice($parts, 1)));
        $httpHeadersNormalized[$headerName] = $headerValue;
    }

    $requestBodyHash = null;
    if (isset($curlOptions[CURLOPT_POSTFIELDS]) === true) {
        $requestBodyHash = md5(Json::encode($curlOptions[CURLOPT_POSTFIELDS], false));
    }
    $cacheKey = implode('_', ['server',  $url, $requestBodyHash]);

    /** @var Response|false|null $cachedResponse */
    $cachedResponse = $cache->get($cacheKey);
    if ($cachedResponse !== false && $cachedResponse !== null) {
        $lastModified = $cachedResponse->getHeader('last-modified');
        if ($lastModified !== null && $lastModified !== '') {
            try {
                // Some servers send Unix timestamp instead of RFC7231 date. Prepend it with @ to allow parsing as DateTime
                $lastModified = new \DateTimeImmutable((is_numeric($lastModified) === true ? '@' : '') . $lastModified);
                $config['if_not_modified_since'] = $lastModified->getTimestamp();
            } catch (\Exception $e) {
                // Failed to parse last-modified
            }
        }
        $etag = $cachedResponse->getHeader('etag');
        if (($etag !== null && $etag !== '') === true) {
            $httpHeadersNormalized['if-none-match'] = $etag;
        }
    }

    $config['headers'] = $httpHeadersNormalized;

    $maxFileSize = Configuration::getConfig('http', 'max_filesize');
    if ($maxFileSize !== null && $maxFileSize !== false) {
        // Convert from MB to B by multiplying with 2^20 (1M)
        $config['max_filesize'] = $maxFileSize * 2 ** 20;
    }

    $proxyUrl = Configuration::getConfig('proxy', 'url');
    if (
        $proxyUrl !== null
        && $proxyUrl !== ''
        && $proxyUrl !== false
        && defined('NOPROXY') === false
    ) {
        $config['proxy'] = $proxyUrl;
    }

    $response = $httpClient->request($url, $config);

    switch ($response->getCode()) {
        case 200:
        case 201:
        case 202:
            $cacheControl = $response->getHeader('cache-control');
            if ($cacheControl !== null && $cacheControl !== '') {
                $directives = explode(',', $cacheControl);
                $directives = array_map('trim', $directives);
                if (
                    in_array('no-cache', $directives, true) === true
                    || in_array('no-store', $directives, true) === true
                ) {
                    // Don't cache as instructed by the server
                    break;
                }
            }
            $cache->set($cacheKey, $response, 86400 * 10);
            break;
        case 301:
        case 302:
        case 303:
            // todo: cache
            break;
        case 304:
            // Not Modified
            $response = $response->withBody($cachedResponse->getBody());
            break;
        default:
            $e = HttpException::fromResponse($response, $url);
            throw $e;
    }
    if ($returnFull === true) {
        return $response;
    }
    return $response->getBody();
}

/**
 * Gets contents from the Internet as HTMLDocument object.
 *
 * @param string $url The URL.
 * @param array $header (optional) A list of cURL headers.
 * @param array $opts (optional) A list of cURL options as associative array.
 *
 * @return \Dom\HTMLDocument Parsed HTML document
 * @throws \Exception If the HTTP response is empty or parsing fails
 */
function getSimpleHTMLDOM(
    string $url,
    array $header = [],
    array $opts = []
): \Dom\HTMLDocument {
    $html = getContents($url, $header, $opts);
    if ($html === '') {
        throw new \Exception('Unable to parse dom because the http response was the empty string');
    }

    libxml_use_internal_errors(true);
    $dom = \Dom\HTMLDocument::createFromString($html);
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    return $dom;
}

/**
 * Fetch contents from the Internet as HTMLDocument object. Contents are cached
 * and re-used for subsequent calls until the cache duration elapsed.
 *
 * @param string $url The URL.
 * @param int $ttl Cache duration in seconds.
 * @param array $header (optional) A list of cURL headers.
 * @param array $opts (optional) A list of cURL options as associative array.
 *
 * @return \Dom\HTMLDocument Parsed HTML document
 * @throws \Exception If the HTTP response is empty or parsing fails
 */
function getSimpleHTMLDOMCached(
    string $url,
    int $ttl = 86400,
    array $header = [],
    array $opts = []
): \Dom\HTMLDocument {
    global $container;

    /** @var CacheInterface $cache */
    $cache = $container['cache'];

    $cacheKey = 'pages_' . $url;
    $content = $cache->get($cacheKey);
    if ($content === null || $content === false) {
        $content = getContents($url, $header, $opts);
        $cache->set($cacheKey, $content, $ttl);
    }

    libxml_use_internal_errors(true);
    $dom = \Dom\HTMLDocument::createFromString($content);
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    return $dom;
}

/**
 * Retrieves HTML via a named proxy profile.
 *
 * @param string $url URL for request
 * @param string $profileName Profile name from config.ini.php (e.g. 'flaresolverr', 'tgws', 'direct')
 * @param array $options Options: cookies, timeout, wait, use_cache, cache_ttl
 * @return string HTML content
 */
function getProtectedContents(string $url, string $profileName, array $options = []): string
{
    $proxy = ProxyFactory::safeFromProfile($profileName);

    try {
        return $proxy->getHtml($url, $options);
    } catch (\Throwable $e) {
        if ($proxy instanceof DirectProxy) {
            throwClientException(sprintf(
                "Failed to fetch %s via DirectProxy: %s\n\nIf this site is protected by Cloudflare, configure a profile in config.ini.php:\n\n  [proxy_profile_flaresolverr]\n  type = \"FlareSolverr\"\n  url = \"http://localhost:8191\"",
                $url,
                $e->getMessage()
            ));
        }
        throwClientException(sprintf("Proxy profile '%s' failed: %s", $profileName, $e->getMessage()));
    }
}

/**
 * Retrieves HTML via a named proxy profile as HTMLDocument object.
 *
 * @param string $url URL for request
 * @param string $profileName Profile name from config.ini.php
 * @param array $options Options: cookies, timeout, wait, use_cache, cache_ttl
 * @return \Dom\HTMLDocument Parsed HTML document
 * @throws \Exception If the proxy returns empty HTML or parsing fails
 */
function getProtectedSimpleHTMLDOM(string $url, string $profileName, array $options = []): \Dom\HTMLDocument
{
    $html = getProtectedContents($url, $profileName, $options);

    if (empty($html) === true) {
        throwClientException(sprintf("Proxy profile '%s' returned empty HTML for: %s", $profileName, $url));
    }

    libxml_use_internal_errors(true);
    $dom = \Dom\HTMLDocument::createFromString($html);
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    return $dom;
}

/**
 * Retrieves binary content (images, videos, etc.) via a named proxy profile.
 *
 * @param string $url URL for request
 * @param string $profileName Profile name from config.ini.php
 * @param array $options Options: timeout, connect_timeout
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
