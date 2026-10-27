<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Http\Response;

/**
 * Privileged HTTP client for requests to this instance's own DOMAIN.
 *
 * Requests are rewritten to hit localhost (127.0.0.1:80) directly, bypassing
 * DNS resolution and TLS certificate verification. The original Host header
 * is preserved so nginx virtual hosting continues to work.
 *
 * SSRF protection is bypassed ONLY here, after strict validation that the
 * target URL points to the configured DOMAIN and is a legitimate bridge
 * display request. All other callers must use getContents() from lib/contents.php,
 * which always enforces SSRF checks.
 */
final class LocalHost
{
    private static ?string $domainHost = null;
    private static ?int $domainPort = null;
    private static bool $domainLoaded = false;

    private const LOCAL_ADDRESS = 'http://127.0.0.1';

    /**
     * Fetches raw body of a local bridge display URL.
     *
     * @param array<string, string>|list<string> $httpHeaders
     * @param array<int, mixed> $curlOptions
     */
    public static function getContents(
        string $url,
        array $httpHeaders = [],
        array $curlOptions = []
    ): string {
        return self::getResponse($url, $httpHeaders, $curlOptions)->getBody();
    }

    /**
     * Fetches full Response object from a local bridge display URL.
     *
     * @param array<string, string>|list<string> $httpHeaders
     * @param array<int, mixed> $curlOptions
     */
    public static function getResponse(
        string $url,
        array $httpHeaders = [],
        array $curlOptions = []
    ): Response {
        self::assertLocalUrl($url);
        return self::fetchLocal($url, $httpHeaders, $curlOptions);
    }

    /**
     * Fetches a local bridge display URL and parses it as HTML.
     *
     * @param array<string, string>|list<string> $httpHeaders
     * @param array<int, mixed> $curlOptions
     */
    public static function getSimpleHTMLDOM(
        string $url,
        array $httpHeaders = [],
        array $curlOptions = []
    ): \Dom\HTMLDocument {
        $html = self::getContents($url, $httpHeaders, $curlOptions);
        if ($html === '') {
            throwClientException(sprintf('LocalHost: empty response from %s', $url));
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $dom;
    }

    /**
     * Validates that the URL points to this instance's DOMAIN and is a
     * legitimate bridge display request. Throws ClientException on failure.
     */
    public static function assertLocalUrl(string $url): void
    {
        self::loadDomainConfig();

        if (self::$domainHost === null) {
            throwServerException('LocalHost: DOMAIN environment variable is not configured');
        }

        $parsed = parse_url($url);
        if (is_array($parsed) === false) {
            throwClientException(sprintf('LocalHost: malformed URL: %s', $url));
        }

        $hasScheme = isset($parsed['scheme']);
        $hasHost = isset($parsed['host']);
        if ($hasScheme === false || $hasHost === false) {
            throwClientException(sprintf('LocalHost: URL must include scheme and host: %s', $url));
        }

        $scheme = strtolower((string) $parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throwClientException(sprintf('LocalHost: unsupported scheme "%s"', $scheme));
        }

        $host = $parsed['host'];
        if (is_string($host) === false || $host === '') {
            throwClientException(sprintf('LocalHost: invalid host in URL: %s', $url));
        }

        if (strcasecmp($host, self::$domainHost) !== 0) {
            throwClientException(sprintf(
                'LocalHost: host mismatch (expected "%s", got "%s")',
                self::$domainHost,
                $host
            ));
        }

        $hasPort = isset($parsed['port']);
        if ($hasPort === true && self::$domainPort !== null) {
            $urlPort = (int) $parsed['port'];
            if ($urlPort !== self::$domainPort) {
                throwClientException(sprintf(
                    'LocalHost: port mismatch (expected %d, got %d)',
                    self::$domainPort,
                    $urlPort
                ));
            }
        }

        $path = $parsed['path'] ?? '/';
        if (is_string($path) === false || str_starts_with($path, '/') === false) {
            throwClientException(sprintf('LocalHost: invalid path in URL: %s', $url));
        }

        $hasQuery = isset($parsed['query']);
        if ($hasQuery === false) {
            throwClientException(sprintf('LocalHost: missing query string: %s', $url));
        }

        parse_str((string) $parsed['query'], $query);
        $action = $query['action'] ?? null;
        $bridge = $query['bridge'] ?? null;

        if ($action !== 'display') {
            throwClientException('LocalHost: URL must be a bridge display request (action=display)');
        }

        if (is_string($bridge) === false || $bridge === '') {
            throwClientException('LocalHost: URL must specify a bridge (bridge=...)');
        }
    }

    /**
     * Lazy-loads and caches DOMAIN configuration from environment.
     */
    private static function loadDomainConfig(): void
    {
        if (self::$domainLoaded === true) {
            return;
        }
        self::$domainLoaded = true;

        $domain = getenv('DOMAIN');
        if (is_string($domain) === false || $domain === '') {
            return;
        }

        $parsed = parse_url($domain);
        if (is_array($parsed) === false) {
            return;
        }

        $hasHost = isset($parsed['host']);
        if ($hasHost === false) {
            return;
        }

        $host = $parsed['host'];
        if (is_string($host) === false || $host === '') {
            return;
        }

        self::$domainHost = strtolower($host);
        $hasPort = isset($parsed['port']);
        if ($hasPort === true) {
            self::$domainPort = (int) $parsed['port'];
        }
    }

    /**
     * Rewrites URL to hit localhost, preserving path, query, and Host header.
     * Bypasses DNS resolution and TLS certificate verification.
     *
     * @param array<string, string>|list<string> $httpHeaders
     * @param array<int, mixed> $curlOptions
     */
    private static function fetchLocal(
        string $url,
        array $httpHeaders,
        array $curlOptions
    ): Response {
        global $container;

        /** @var \RSSBridge\Http\HttpClient $httpClient */
        $httpClient = $container['http_client'];

        $timeoutEnv = getenv('HTTP_TIMEOUT');
        $retriesEnv = getenv('HTTP_RETRIES');

        $timeoutValue = ($timeoutEnv !== false && $timeoutEnv !== '') ? (int) $timeoutEnv : 60;
        $retriesValue = ($retriesEnv !== false && $retriesEnv !== '') ? (int) $retriesEnv : 2;

        $parsed = parse_url($url);
        if (is_array($parsed) === false) {
            throwServerException('LocalHost: failed to parse URL for local fetch');
        }

        $path = $parsed['path'] ?? '/';
        $queryString = $parsed['query'] ?? '';
        $originalHost = $parsed['host'];

        $localUrl = self::LOCAL_ADDRESS . $path;
        if ($queryString !== '') {
            $localUrl .= '?' . $queryString;
        }

        $headers = [];
        $hasHostHeader = false;
        foreach ($httpHeaders as $key => $value) {
            if (is_string($key) === true) {
                $headers[$key] = (string) $value;
                if (strcasecmp($key, 'Host') === 0) {
                    $hasHostHeader = true;
                }
            } else {
                $parts = explode(':', (string) $value, 2);
                if (count($parts) === 2) {
                    $headerName = trim($parts[0]);
                    $headers[$headerName] = trim($parts[1]);
                    if (strcasecmp($headerName, 'Host') === 0) {
                        $hasHostHeader = true;
                    }
                }
            }
        }

        if ($hasHostHeader === false && is_string($originalHost) === true) {
            $headers['Host'] = $originalHost;
        }

        $config = [
            'timeout'      => $timeoutValue,
            'retries'      => $retriesValue,
            'curl_options' => $curlOptions + [CURLOPT_ENCODING => ''],
            'headers'      => $headers,
            'ssrf_check'   => false,
        ];

        return $httpClient->request($localUrl, $config);
    }
}
