<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;
use RSSBridge\Http\Response;
use RSSBridge\Utils\Json;
use RSSBridge\Proxies\DirectProxy;
use RSSBridge\Proxies\ProxyFactory;

function get_sitemap(string $url): array
{
    $xmlString = getContents($url);
    if ($xmlString === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $doc = \Dom\XMLDocument::createFromString($xmlString, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    if ($doc->documentElement === null) {
        return [];
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
            if ($element instanceof \Dom\Element) {
                $localName = $element->localName;
                $prefix = $element->prefix ?? 'news';
                $item[$prefix][$localName] = $element->textContent;
            }
        }
        $urls[] = $item;
    }
    return $urls;
}

/**
 * Computes the unix timestamp until which a cached upstream response may be
 * served without contacting the origin, based on the server's own freshness
 * signals (RFC 9110 section 4.2.1 priority order).
 */
function responseFreshUntil(Response $response): ?int
{
    $cacheControl = $response->getHeader('cache-control');
    if ($cacheControl !== null && $cacheControl !== '') {
        $directives = array_map('trim', explode(',', strtolower($cacheControl)));
        foreach (['no-store', 'no-cache', 'must-revalidate'] as $blocking) {
            if (in_array($blocking, $directives, true) === true) {
                return null;
            }
        }
        foreach ($directives as $directive) {
            if (str_starts_with($directive, 'max-age=') === true) {
                $seconds = (int) substr($directive, strlen('max-age='));
                $date = $response->getHeader('date');
                $anchor = $date !== null ? strtotime($date) : false;
                if ($anchor === false) {
                    $anchor = time();
                }
                $age = $response->getHeader('age');
                if ($age !== null && $age !== '' && ctype_digit($age) === true) {
                    $anchor -= (int) $age;
                }
                return $anchor + max(0, $seconds);
            }
        }
    }

    $expires = $response->getHeader('expires');
    if ($expires !== null && $expires !== '') {
        $timestamp = strtotime($expires);
        if ($timestamp === false) {
            return time() - 1;
        }
        return $timestamp;
    }

    return null;
}

/**
 * Fetch data from an http url
 *
 * @param array<string, string>|list<string> $httpHeaders E.g. ['Accept' => 'application/json'] or ['Accept: application/json']
 * @param array<int, mixed> $curlOptions Associative array e.g. [CURLOPT_MAXREDIRS => 3]
 * @return string|Response
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

    $timeout = getenv('HTTP_TIMEOUT');
    $retries = getenv('HTTP_RETRIES');

    $config = [
        'timeout'      => $timeout !== false ? (int) $timeout : 60,
        'retries'      => $retries !== false ? (int) $retries : 2,
        'curl_options' => $curlOptions + [CURLOPT_ENCODING => ''],
    ];

    $httpHeadersNormalized = [];
    foreach ($httpHeaders as $key => $value) {
        if (is_string($key) === true) {
            // Modern associative array format: ['Header-Name' => 'Value']
            $httpHeadersNormalized[$key] = (string) $value;
        } else {
            // Legacy list format fallback: ['Header-Name: Value']
            $parts = explode(':', (string) $value, 2);
            if (count($parts) === 2) {
                $httpHeadersNormalized[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    $requestBodyHash = null;
    if (isset($curlOptions[CURLOPT_POSTFIELDS]) === true) {
        $requestBodyHash = md5(Json::encode($curlOptions[CURLOPT_POSTFIELDS], false));
    }
    $cacheKey = implode('_', ['server', $url, $requestBodyHash]);

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

        $lastModified = $cachedResponse->getHeader('last-modified');
        if ($lastModified !== null && $lastModified !== '') {
            try {
                $lastModifiedStr = is_numeric($lastModified) === true ? '@' . $lastModified : $lastModified;
                $lastModifiedDate = new \DateTimeImmutable($lastModifiedStr);
                $config['if_not_modified_since'] = $lastModifiedDate->getTimestamp();
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

    $maxFileSize = getenv('HTTP_MAX_FILESIZE');
    if ($maxFileSize !== false && $maxFileSize !== '' && (int)$maxFileSize > 0) {
        $config['max_filesize'] = (int)$maxFileSize * 2 ** 20;
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
                    break;
                }
            }
            $cache->set($cacheKey, $response, 86400);
            break;
        case 301:
        case 302:
        case 303:
            break;
        case 304:
            if ($cachedResponse instanceof Response) {
                $response = $response->withBody($cachedResponse->getBody());
            }
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

/**
 * Gets contents from the Internet as HTMLDocument object.
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
 * Retrieves HTML via a named proxy profile.
 *
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
 * @return \Dom\HTMLDocument Parsed HTML document
 * @throws \Exception If the proxy returns empty HTML or parsing fails
 */
function getProtectedSimpleHTMLDOM(string $url, string $profileName, array $options = []): \Dom\HTMLDocument
{
    $html = getProtectedContents($url, $profileName, $options);

    if ($html === '') {
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
