<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

final class FlareSolverrProxy extends ProxyAbstract
{
    private const SESSION_FLAG_PREFIX = 'flaresolverr_session_created_';
    private const SESSION_FLAG_TTL = 86400;
    private const CURL_TIMEOUT_HEADROOM = 30;

    private string $baseUrl = '';
    private string $apiUrl = '';
    private string $sessionName = '';
    private int $maxTimeoutMs = 180000;
    private ?int $sessionTtlMinutes = null;

    protected function initialize(): void
    {
        $this->baseUrl = rtrim(self::toString($this->config['url'] ?? ''), '/');
        $this->apiUrl = $this->baseUrl . '/v1';
        $this->sessionName = self::toString($this->config['session_name'] ?? 'rss_bridge_session', 'rss_bridge_session');
        $this->maxTimeoutMs = max(1000, self::toInt($this->config['timeout'] ?? 180000, 180000));
        $this->timeout = intdiv($this->maxTimeoutMs, 1000) + self::CURL_TIMEOUT_HEADROOM;
        $ttlMinutes = self::toInt($this->config['session_ttl_minutes'] ?? 0, 0);
        $this->sessionTtlMinutes = $ttlMinutes > 0 ? $ttlMinutes : null;
    }

    public function getName(): string
    {
        return 'FlareSolverr';
    }

    public function isAvailable(): bool
    {
        if ($this->baseUrl === '') {
            return false;
        }

        try {
            $response = $this->request('GET', $this->baseUrl . '/health');
            return ($response['status'] ?? '') === 'ok';
        } catch (\Exception $e) {
            $this->log('warning', 'FlareSolverr unavailable: ' . $e->getMessage());
            return false;
        }
    }

    protected function validateResponse(string $response): bool
    {
        if ($response === '') {
            return false;
        }

        $markers = [
            '<title>just a moment',
            '<title>checking your browser',
            '<title>attention required! | cloudflare',
            '<title>ddos-guard',
            'cf-browser-verification',
            'window._cf_chl_opt',
            'id="challenge-form"',
            'id="cf-challenge-running"',
        ];

        foreach ($markers as $marker) {
            if (stripos($response, $marker) !== false) {
                $this->log('warning', 'Cloudflare challenge detected: ' . $marker);
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
        $payload = $this->buildRequestPayload($url, $options, false);
        $response = $this->executeWithSessionRecovery($payload);

        $html = $this->extractSolutionHtml($response);
        if ($html === null) {
            throwServerException('FlareSolverr did not return HTML content');
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{body: string, type: string}
     */
    public function getBinary(string $url, array $options = []): array
    {
        $this->log('info', 'Fetching binary ' . $url . ' via FlareSolverr');

        $payload = $this->buildRequestPayload($url, $options, true);
        $response = $this->executeWithSessionRecovery($payload);

        $solution = $response['solution'] ?? null;
        if (is_array($solution) === false) {
            throwServerException('FlareSolverr did not return a solution');
        }

        $userAgent = self::toString($solution['userAgent'] ?? '');
        $cookieHeader = $this->buildCookieHeader($solution['cookies'] ?? null);

        return $this->fetchBinaryDirect($url, $userAgent, $cookieHeader, $options);
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
                    sleep(2 ** ($attempt - 1));
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
            $this->log('error', 'FlareSolverr request failed', ['url' => $url, 'error' => $error]);
            throwServerException('FlareSolverr request failed: ' . ($error !== '' ? $error : 'no response'));
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
            $this->log('error', 'Invalid JSON response from FlareSolverr', ['http_code' => $httpCode, 'response' => substr($body, 0, 500)]);
            throwServerException(sprintf('FlareSolverr returned invalid JSON (HTTP %d): %s', $httpCode, substr($body, 0, 200)));
        }

        /** @var array<string, mixed> $result */

        if (($result['status'] ?? '') !== 'ok') {
            $apiMessage = self::toString($result['message'] ?? $result['error'] ?? 'Unknown error', 'Unknown error');
            $this->log('error', 'FlareSolverr API error', ['http_code' => $httpCode, 'message' => $apiMessage]);
            if (stripos($apiMessage, 'session') !== false) {
                $this->invalidateSessionFlag();
            }
            throwServerException('FlareSolverr API error: ' . $apiMessage);
        }

        if ($httpCode !== 200) {
            throwServerException(sprintf('FlareSolverr returned HTTP %d with a successful status', $httpCode));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildRequestPayload(string $url, array $options, bool $returnOnlyCookies): array
    {
        $waitMs = $returnOnlyCookies === true ? 0 : $this->calculateWaitTime($url, $options);

        $payload = [
            'cmd' => 'request.get',
            'url' => $url,
            'maxTimeout' => self::toInt($options['timeout'] ?? $this->maxTimeoutMs, $this->maxTimeoutMs),
            'waitInSeconds' => max(0, intdiv($waitMs, 1000)),
        ];

        if ($returnOnlyCookies === true) {
            $payload['returnOnlyCookies'] = true;
        }

        $cookies = $options['cookies'] ?? [];
        if (is_array($cookies) === true && $cookies !== []) {
            $payload['cookies'] = $cookies;
        }

        if ($this->sessionName !== '') {
            $payload['session'] = $this->sessionName;
            if ($this->sessionTtlMinutes !== null) {
                $payload['session_ttl_minutes'] = $this->sessionTtlMinutes;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeWithSessionRecovery(array $payload): array
    {
        $this->ensureSession();

        try {
            return $this->request('POST', $this->apiUrl, $payload);
        } catch (\Exception $e) {
            if ($this->sessionName === '' || stripos($e->getMessage(), 'session') === false) {
                throw $e;
            }

            $this->log('warning', 'FlareSolverr session error, recreating session and retrying: ' . $e->getMessage());
            $this->invalidateSessionFlag();
            $this->ensureSession();

            return $this->request('POST', $this->apiUrl, $payload);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function calculateWaitTime(string $url, array $options): int
    {
        if (isset($options['wait']) === true) {
            return self::toInt($options['wait'], 5000);
        }

        $parsedHost = parse_url($url, PHP_URL_HOST);
        $domain = is_string($parsedHost) === true ? $parsedHost : 'unknown';

        if ($this->cache !== null) {
            $cacheKey = 'flaresolverr_domain_visited_' . md5($domain);
            if ($this->cache->get($cacheKey) !== null) {
                return 2000;
            }
            $this->cache->set($cacheKey, time(), 3600);
        }

        return 5000;
    }

    private function ensureSession(): void
    {
        if ($this->sessionName === '') {
            return;
        }

        $cacheKey = self::SESSION_FLAG_PREFIX . md5($this->sessionName);
        if ($this->cache !== null && $this->cache->get($cacheKey) !== null) {
            return;
        }

        $response = $this->request('POST', $this->apiUrl, ['cmd' => 'sessions.list']);
        $sessions = $response['sessions'] ?? [];
        $sessionExists = is_array($sessions) === true && in_array($this->sessionName, $sessions, true) === true;

        if ($sessionExists === false) {
            $this->log('info', 'Creating session: ' . $this->sessionName);
            $this->request('POST', $this->apiUrl, ['cmd' => 'sessions.create', 'session' => $this->sessionName]);
        }

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, time(), self::SESSION_FLAG_TTL);
        }
    }

    private function invalidateSessionFlag(): void
    {
        if ($this->cache !== null && $this->sessionName !== '') {
            $this->cache->delete(self::SESSION_FLAG_PREFIX . md5($this->sessionName));
        }
    }

    private function isRetryableError(\Exception $e): bool
    {
        if ($e instanceof \JsonException) {
            return false;
        }

        $message = $e->getMessage();

        if (stripos($message, 'session') !== false) {
            return false;
        }

        $markers = [
            'Cloudflare has blocked this request',
            'Captcha detected',
            "Request parameter 'cmd'",
            "Request parameter 'url'",
        ];

        foreach ($markers as $marker) {
            if (stripos($message, $marker) !== false) {
                return false;
            }
        }

        return true;
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
     * @param array<string, mixed> $response
     */
    private function extractSolutionHtml(array $response): ?string
    {
        $solution = $response['solution'] ?? null;
        if (is_array($solution) === false) {
            return null;
        }

        $html = $solution['response'] ?? null;
        if (is_string($html) === false || $html === '') {
            return null;
        }

        return $html;
    }

    private function buildCookieHeader(mixed $cookies): string
    {
        if (is_array($cookies) === false) {
            return '';
        }

        $pairs = [];
        foreach ($cookies as $cookie) {
            if (is_array($cookie) === false) {
                continue;
            }
            $name = self::toString($cookie['name'] ?? '');
            $value = self::toString($cookie['value'] ?? '');
            if ($name !== '') {
                $pairs[] = $name . '=' . $value;
            }
        }

        return implode('; ', $pairs);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{body: string, type: string}
     */
    private function fetchBinaryDirect(string $url, string $userAgent, string $cookieHeader, array $options): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throwServerException('Failed to initialize cURL');
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($userAgent !== '') {
            $curlOptions[CURLOPT_USERAGENT] = $userAgent;
        }
        if ($cookieHeader !== '') {
            $curlOptions[CURLOPT_COOKIE] = $cookieHeader;
        }

        $curlHeaders = $this->buildCurlHeaders($options['headers'] ?? null);
        if ($curlHeaders !== []) {
            $curlOptions[CURLOPT_HTTPHEADER] = $curlHeaders;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->log('error', 'Binary fetch failed', ['url' => $url, 'http_code' => $httpCode, 'error' => $error]);
            throwServerException(sprintf('Binary fetch failed for %s: HTTP %d %s', $url, $httpCode, $error));
        }

        $type = is_string($contentType) === true && $contentType !== '' ? trim(explode(';', $contentType)[0]) : $this->mimeFromUrl($url);

        return ['body' => (string) $response, 'type' => $type];
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
