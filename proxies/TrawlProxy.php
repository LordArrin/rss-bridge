<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

final class TrawlProxy extends ProxyAbstract
{
    private const CURL_TIMEOUT_HEADROOM = 30;
    private const SESSION_FLAG_PREFIX = 'trawl_session_created_';
    private const SESSION_FLAG_TTL = 1800;

    private string $apiUrl = '';
    private int $maxTimeoutMs = 180000;
    private bool $useForwardProxy = false;
    private string $forwardProxyHost = 'localhost';
    private int $forwardProxyPort = 8192;
    private ?string $configuredCaFile = null;

    protected function initialize(): void
    {
        $this->apiUrl = rtrim(self::toString($this->config['url'] ?? ''), '/');
        $this->maxTimeoutMs = max(1000, self::toInt($this->config['timeout'] ?? 180000, 180000));
        $this->timeout = intdiv($this->maxTimeoutMs, 1000) + self::CURL_TIMEOUT_HEADROOM;
        $this->useForwardProxy = self::toBool($this->config['use_forward_proxy'] ?? false, false);
        $this->forwardProxyPort = self::toInt($this->config['forward_proxy_port'] ?? 8192, 8192);

        $parsed = parse_url($this->apiUrl);
        $this->forwardProxyHost = is_array($parsed) === true && isset($parsed['host']) === true ? $parsed['host'] : 'localhost';

        $caFile = self::toString($this->config['forward_proxy_ca_file'] ?? '');
        $this->configuredCaFile = $caFile !== '' ? $caFile : null;

        if ($this->useForwardProxy === true) {
            $this->log('info', 'Trawl forward proxy enabled: ' . $this->forwardProxyHost . ':' . $this->forwardProxyPort);
        }
    }

    public function getName(): string
    {
        return 'Trawl';
    }

    public function isAvailable(): bool
    {
        if ($this->apiUrl === '') {
            return false;
        }

        try {
            $response = $this->request('GET', $this->apiUrl . '/health');
            return ($response['status'] ?? '') === 'ok';
        } catch (\Exception $e) {
            $this->log('warning', 'Trawl unavailable: ' . $e->getMessage());
            return false;
        }
    }

    protected function validateResponse(string $response): bool
    {
        if ($response === '' || strlen($response) < 100) {
            $this->log('warning', 'Response too short or empty');
            return false;
        }

        $errorMarkers = [
            'Access denied',
            'Forbidden',
            '403 Forbidden',
            '404 Not Found',
            '500 Internal Server Error',
            '502 Bad Gateway',
            '503 Service Unavailable',
            '504 Gateway Timeout',
        ];

        foreach ($errorMarkers as $marker) {
            if (stripos($response, '<title>' . $marker) !== false) {
                $this->log('warning', 'Error page detected: ' . $marker);
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function fetchHtml(string $url, array $options): string
    {
        $sessionId = $options['session_id'] ?? null;
        $sessionId = is_string($sessionId) === true && $sessionId !== '' ? $sessionId : null;

        $payload = $this->buildScrapePayload($url, $options, $sessionId);
        $response = $this->executeScrape($payload, $sessionId);

        $statusCode = self::toInt($response['statusCode'] ?? 200, 200);
        if ($statusCode >= 400) {
            throwServerException(sprintf('Trawl fetched %s but the target returned HTTP %d', $url, $statusCode));
        }

        $responseCookies = $response['cookies'] ?? [];
        $cookiesCount = is_array($responseCookies) === true ? count($responseCookies) : 0;

        $this->log('info', 'Trawl scrape completed', [
            'url' => $url,
            'tier' => $response['tier'] ?? null,
            'total_ms' => $response['totalMs'] ?? null,
            'cookies_count' => $cookiesCount,
            'session_cached' => self::toBool($response['sessionCached'] ?? false, false),
        ]);

        $html = $response['html'] ?? null;
        if (is_string($html) === false) {
            $encoded = json_encode($response);
            $responseDump = is_string($encoded) === true ? substr($encoded, 0, 500) : '';
            throwServerException('Trawl did not return HTML content. Response: ' . $responseDump);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{body: string, type: string}
     */
    public function getBinary(string $url, array $options = []): array
    {
        $this->log('info', 'Fetching binary ' . $url . ' via Trawl');

        if ($this->useForwardProxy === false) {
            throwServerException('Trawl binary fetch requires the forward proxy: set PROXY_PROFILE_TRAWL_USE_FORWARD_PROXY=true and MITM_ENABLED=true on the Trawl server');
        }

        return $this->getBinaryViaForwardProxy($url, $options);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    protected function request(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $attempt = 0;
        $lastError = '';

        while ($attempt < $this->maxRetries) {
            try {
                return $this->executeRequest($method, $url, $payload, $headers);
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                if ($this->isRetryableError($e) === false) {
                    throw $e;
                }
                $attempt++;
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelaySeconds($e, $attempt));
                }
            }
        }

        throw new \RuntimeException(sprintf('Proxy request failed after %d attempts: %s', $this->maxRetries, $lastError));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throwServerException('Failed to initialize cURL');
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT => $this->curlTimeoutSeconds($payload),
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false) {
            $this->log('error', 'Trawl request failed', ['url' => $url, 'error' => $error]);
            throwServerException('Trawl request failed: ' . ($error !== '' ? $error : 'no response'));
        }

        $body = (string) $response;
        $result = null;

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) === true) {
                $result = $decoded;
            }
        } catch (\JsonException) {
            $result = null;
        }

        if ($result === null) {
            $this->log('error', 'Invalid JSON response from Trawl', ['http_code' => $httpCode, 'response' => substr($body, 0, 500)]);
            throwServerException(sprintf('Trawl returned invalid JSON (HTTP %d): %s', $httpCode, substr($body, 0, 200)));
        }

        /** @var array<string, mixed> $result */

        if ($httpCode !== 200 && $httpCode !== 201) {
            $apiMessage = $this->extractErrorMessage($result);
            $this->log('error', 'Trawl API error', ['http_code' => $httpCode, 'message' => $apiMessage, 'timings' => $result['timings'] ?? []]);

            if ($httpCode === 429) {
                throwRateLimitException('Trawl API error (HTTP 429): ' . $apiMessage, 60);
            }
            if ($httpCode === 503) {
                throwServiceUnavailableException('Trawl API error (HTTP 503): ' . $apiMessage, 60);
            }
            throwServerException(sprintf('Trawl API error (HTTP %d): %s', $httpCode, $apiMessage));
        }

        $errorField = $result['error'] ?? null;
        if (is_string($errorField) === true && $errorField !== '') {
            throwServerException('Trawl API error: ' . $errorField);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildScrapePayload(string $url, array $options, ?string $sessionId): array
    {
        $maxTier = max(1, min(4, self::toInt($options['max_tier'] ?? 4, 4)));
        if ($sessionId !== null) {
            $maxTier = max(3, $maxTier);
        }

        $payload = [
            'url' => $url,
            'maxTimeout' => self::toInt($options['timeout'] ?? $this->maxTimeoutMs, $this->maxTimeoutMs),
            'maxTier' => $maxTier,
            'solveEmbeddedCaptchas' => true,
        ];

        $cookies = $options['cookies'] ?? [];
        if (is_array($cookies) === true && $cookies !== []) {
            $payload['cookies'] = $cookies;
        }

        $headers = $this->normalizeApiHeaders($options['headers'] ?? null);
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        if ($sessionId !== null) {
            $payload['sessionId'] = $sessionId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeScrape(array $payload, ?string $sessionId): array
    {
        if ($sessionId !== null) {
            $this->ensureSession($sessionId);
        }

        try {
            return $this->request('POST', $this->apiUrl . '/scrape', $payload);
        } catch (\ServerException $e) {
            $message = $e->getMessage();
            if ($sessionId === null || (str_contains($message, '(HTTP 404)') === false && str_contains($message, '(HTTP 410)') === false)) {
                throw $e;
            }

            $this->log('warning', 'Trawl session expired, recreating and retrying: ' . $message);
            if ($this->cache !== null) {
                $this->cache->delete(self::SESSION_FLAG_PREFIX . md5($sessionId));
            }
            $this->ensureSession($sessionId);

            return $this->request('POST', $this->apiUrl . '/scrape', $payload);
        }
    }

    private function ensureSession(string $sessionId): void
    {
        $cacheKey = self::SESSION_FLAG_PREFIX . md5($sessionId);
        if ($this->cache !== null && $this->cache->get($cacheKey) !== null) {
            return;
        }

        try {
            $this->request('POST', $this->apiUrl . '/sessions', ['id' => $sessionId]);
        } catch (\ServerException $e) {
            if (str_contains($e->getMessage(), '(HTTP 409)') === false) {
                throw $e;
            }
        }

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, time(), self::SESSION_FLAG_TTL);
        }
    }

    private function isRetryableError(\Exception $e): bool
    {
        if ($e instanceof \JsonException) {
            return false;
        }
        if ($e instanceof \RateLimitException || $e instanceof \ServiceUnavailableException) {
            return true;
        }

        $message = $e->getMessage();
        if (str_contains($message, '(HTTP 4') === true) {
            return false;
        }
        if (stripos($message, 'All tiers exhausted') !== false) {
            return false;
        }

        return true;
    }

    private function retryDelaySeconds(\Exception $e, int $attempt): int
    {
        if ($e instanceof \RateLimitException || $e instanceof \ServiceUnavailableException) {
            return 5 * $attempt;
        }

        return (int) (2 ** ($attempt - 1));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function curlTimeoutSeconds(array $payload): int
    {
        if (isset($payload['maxTimeout']) === true) {
            return intdiv(max(1000, self::toInt($payload['maxTimeout'], $this->maxTimeoutMs)), 1000) + self::CURL_TIMEOUT_HEADROOM;
        }

        return $this->timeout;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractErrorMessage(array $result): string
    {
        $error = $result['error'] ?? null;
        if (is_string($error) === true && $error !== '') {
            return $error;
        }

        $message = $result['message'] ?? null;
        if (is_string($message) === true && $message !== '') {
            return $message;
        }

        return 'Unknown error';
    }

    /**
     * @return array<string, string>
     */
    private function normalizeApiHeaders(mixed $headers): array
    {
        if (is_array($headers) === false) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            $valueStr = self::toString($value);
            if ($valueStr === '') {
                continue;
            }

            if (is_string($key) === true) {
                $name = trim($key);
                $headerValue = $valueStr;
            } else {
                if (str_contains($valueStr, ':') === false) {
                    continue;
                }
                $parts = explode(':', $valueStr, 2);
                $name = trim($parts[0]);
                $headerValue = trim($parts[1]);
            }

            if ($name === '' || $headerValue === '') {
                continue;
            }

            $lower = strtolower($name);
            if (in_array($lower, ['authorization', 'cookie', 'proxy-authorization', 'user-agent', 'host', 'content-length'], true) === true) {
                continue;
            }
            if (str_starts_with($lower, 'sec-') === true) {
                continue;
            }

            $normalized[$name] = $headerValue;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{body: string, type: string}
     */
    private function getBinaryViaForwardProxy(string $url, array $options): array
    {
        $proxyUrl = 'http://' . $this->forwardProxyHost . ':' . $this->forwardProxyPort;
        $caFile = $this->resolveProxyCaFile();

        try {
            return $this->executeForwardProxyRequest($url, $options, $proxyUrl, $caFile);
        } catch (\ServerException $e) {
            $message = $e->getMessage();
            $isTlsError = stripos($message, 'certificate') !== false || stripos($message, 'ssl') !== false;
            if ($caFile === null || $this->configuredCaFile !== null || $isTlsError === false) {
                throw $e;
            }

            $this->log('warning', 'Trawl forward proxy TLS failure, refreshing CA and retrying without verification: ' . $message);
            if (is_file($caFile) === true) {
                unlink($caFile);
            }

            return $this->executeForwardProxyRequest($url, $options, $proxyUrl, null);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{body: string, type: string}
     */
    private function executeForwardProxyRequest(string $url, array $options, string $proxyUrl, ?string $caFile): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throwServerException('Failed to initialize cURL for forward proxy');
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => $proxyUrl,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($caFile !== null && $caFile !== '') {
            $curlOptions[CURLOPT_CAINFO] = $caFile;
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
        } else {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        $curlHeaders = $this->buildCurlHeaders($options['headers'] ?? null);
        $targetHost = parse_url($url, PHP_URL_HOST);
        $cookieHeader = $this->buildCookieHeader($options['cookies'] ?? null, is_string($targetHost) === true ? $targetHost : '');
        if ($cookieHeader !== '') {
            $curlHeaders[] = 'Cookie: ' . $cookieHeader;
        }
        if ($curlHeaders !== []) {
            $curlOptions[CURLOPT_HTTPHEADER] = $curlHeaders;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->log('error', 'Forward proxy failed', ['url' => $url, 'http_code' => $httpCode, 'error' => $error]);
            $hint = $httpCode === 0 ? ' (check MITM_ENABLED=true on the Trawl server and that port ' . $this->forwardProxyPort . ' is reachable)' : '';
            throwServerException(sprintf('Forward proxy failed for %s: HTTP %d %s%s', $url, $httpCode, $error, $hint));
        }

        $type = is_string($contentType) === true && $contentType !== '' ? trim(explode(';', $contentType)[0]) : $this->mimeFromUrl($url);

        $this->log('info', 'Binary fetched via forward proxy', [
            'url' => $url,
            'http_code' => $httpCode,
            'content_type' => $type,
            'size' => strlen((string) $response),
        ]);

        return ['body' => (string) $response, 'type' => $type];
    }

    private function resolveProxyCaFile(): ?string
    {
        if ($this->configuredCaFile !== null) {
            if (is_readable($this->configuredCaFile) === true) {
                return $this->configuredCaFile;
            }
            $this->log('warning', 'Configured Trawl CA file is not readable: ' . $this->configuredCaFile);
            return null;
        }

        $caFile = sys_get_temp_dir() . '/trawl_proxy_ca_' . md5($this->apiUrl) . '.crt';
        if (is_readable($caFile) === true) {
            $size = filesize($caFile);
            if ($size !== false && $size > 0) {
                return $caFile;
            }
        }

        $ch = curl_init($this->apiUrl . '/proxy-ca.crt');
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode !== 200) {
            $this->log('warning', 'Failed to download Trawl proxy CA, falling back to unverified TLS');
            return null;
        }

        $pem = (string) $response;
        if (str_contains($pem, 'BEGIN CERTIFICATE') === false) {
            $this->log('warning', 'Trawl proxy CA endpoint returned no certificate, falling back to unverified TLS');
            return null;
        }

        if (file_put_contents($caFile, $pem) === false) {
            $this->log('warning', 'Failed to store Trawl proxy CA, falling back to unverified TLS');
            return null;
        }

        return $caFile;
    }

    /**
     * @return list<string>
     */
    private function buildCurlHeaders(mixed $headers): array
    {
        if (is_array($headers) === false) {
            return [];
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $valueStr = self::toString($value);
            if ($valueStr === '') {
                continue;
            }
            if (is_string($key) === true) {
                if ($key !== '') {
                    $curlHeaders[] = $key . ': ' . $valueStr;
                }
            } else {
                $curlHeaders[] = $valueStr;
            }
        }

        return $curlHeaders;
    }

    private function buildCookieHeader(mixed $cookies, string $host): string
    {
        if (is_array($cookies) === false) {
            return '';
        }

        $host = strtolower($host);
        $pairs = [];
        foreach ($cookies as $cookie) {
            if (is_array($cookie) === false) {
                continue;
            }
            $name = self::toString($cookie['name'] ?? '');
            $value = self::toString($cookie['value'] ?? '');
            if ($name === '') {
                continue;
            }
            $domain = strtolower(ltrim(self::toString($cookie['domain'] ?? ''), '.'));
            if ($domain !== '' && $host !== $domain && str_ends_with($host, '.' . $domain) === false) {
                continue;
            }
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }

    private function mimeFromUrl(string $url): string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($urlPath) === true ? $urlPath : '', PATHINFO_EXTENSION));

        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
        ];

        return $mimeMap[$extension] ?? 'application/octet-stream';
    }
}
