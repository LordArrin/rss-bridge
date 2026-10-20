<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

use function RSSBridge\Exceptions\throwServerException;

/**
 * Trawl proxy integration.
 *
 * Trawl is a self-hosted web scraping engine that bypasses JavaScript challenges
 * and CAPTCHAs (Cloudflare, Turnstile, reCAPTCHA, hCaptcha, GeeTest, etc.).
 *
 * Features:
 * - Native /scrape endpoint with multi-tier challenge solving (tiers 1-4)
 * - Support for embedded CAPTCHAs (Turnstile, reCAPTCHA v2, hCaptcha, GeeTest, ALTCHA)
 * - Optional 2Captcha fallback with lazy validation (cached until container restart)
 * - Isolated browser sessions for stateful scraping
 * - Forward proxy (port 8192) for fast HTTP requests without browser overhead
 * - Automatic clearance cache reuse for repeat requests
 * - Custom headers, cookies, and session management
 *
 * Configuration example in config.ini.php:
 *
 * [proxy_profile_trawl]
 * type = "Trawl"
 * url = "http://trawl:8191"
 * timeout = 180000
 * twocaptcha_api_key = ""       ; Optional: for advanced CAPTCHA solving (lazy validated, cached)
 * use_forward_proxy = true      ; Optional: use forward proxy for binary/simple requests (faster)
 * forward_proxy_port = 8192     ; Optional: forward proxy port (default: 8192)
 */
final class TrawlProxy extends ProxyAbstract
{
    private ?string $apiUrl = null;
    private ?string $twocaptchaApiKey = null;
    private bool $useForwardProxy = false;
    private ?string $forwardProxyHost = null;
    private int $forwardProxyPort = 8192;

    /**
     * Cached validation state for 2Captcha API key.
     * Persists for the lifetime of the PHP process (until container restart).
     * Values: null = not yet validated, true = valid, false = invalid
     */
    private static ?bool $twocaptchaValid = null;

    protected function initialize(): void
    {
        $this->apiUrl = rtrim($this->config['url'] ?? '', '/');
        $this->timeout = (int)($this->config['timeout'] ?? 180000) / 1000;

        $apiKey = (string)($this->config['twocaptcha_api_key'] ?? '');
        $this->twocaptchaApiKey = $apiKey !== '' ? $apiKey : null;

        $this->useForwardProxy = (bool)($this->config['use_forward_proxy'] ?? false);

        if ($this->useForwardProxy === true) {
            $parsed = parse_url($this->apiUrl);
            $this->forwardProxyHost = is_array($parsed) === true && isset($parsed['host']) === true ? (string)$parsed['host'] : 'localhost';
            $this->forwardProxyPort = (int)($this->config['forward_proxy_port'] ?? 8192);
        }

        if ($this->twocaptchaApiKey !== null) {
            $this->log('info', 'Trawl initialized with 2Captcha API key (will be lazy validated on first use)');
        }

        if ($this->useForwardProxy === true) {
            $this->log('info', "Trawl forward proxy enabled: {$this->forwardProxyHost}:{$this->forwardProxyPort}");
        }
    }

    public function getName(): string
    {
        return 'Trawl';
    }

    public function isAvailable(): bool
    {
        if (($this->config['url'] ?? '') === '') {
            return false;
        }

        try {
            $response = $this->request('GET', $this->apiUrl . '/health');
            return isset($response['status']) === true;
        } catch (\Exception $e) {
            $this->log('warning', 'Trawl unavailable: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Checks if 2Captcha API key is available and valid.
     * Uses static cache to avoid repeated validation within the same process.
     */
    private function isTwocaptchaAvailable(): bool
    {
        // Fast path: already validated
        if (self::$twocaptchaValid !== null) {
            return self::$twocaptchaValid;
        }

        // No key configured
        if ($this->twocaptchaApiKey === null) {
            self::$twocaptchaValid = false;
            return false;
        }

        // Assume valid until proven otherwise (lazy validation)
        self::$twocaptchaValid = true;
        $this->log('info', '2Captcha API key will be used for CAPTCHA solving (lazy validation)');
        return true;
    }

    /**
     * Marks 2Captcha key as invalid and caches this state.
     * Will not attempt to use the key again until container restart.
     */
    private function invalidateTwocaptcha(string $reason): void
    {
        self::$twocaptchaValid = false;
        $this->log('warning', "2Captcha API key marked as invalid and will not be used until container restart. Reason: {$reason}");
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
                $this->log('warning', "Error page detected: {$marker}");
                return false;
            }
        }

        return true;
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $payload = [
            'url' => $url,
            'maxTimeout' => $options['timeout'] ?? 180000,
            'maxTier' => $options['max_tier'] ?? 4,
            'solveEmbeddedCaptchas' => true,
        ];

        $cookies = $options['cookies'] ?? [];
        if ($cookies !== []) {
            $payload['cookies'] = $cookies;
        }

        $headers = $options['headers'] ?? [];
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        if (isset($options['session_id']) === true && $options['session_id'] !== '') {
            $payload['sessionId'] = $options['session_id'];
        }

        $useTwocaptcha = $this->isTwocaptchaAvailable();
        if ($useTwocaptcha === true) {
            $payload['twocaptchaApiKey'] = $this->twocaptchaApiKey;
        }

        $this->log('debug', 'Sending request to Trawl', [
            'url' => $url,
            'max_tier' => $payload['maxTier'],
            'solve_captchas' => $payload['solveEmbeddedCaptchas'],
            'has_2captcha' => $useTwocaptcha,
        ]);

        try {
            $response = $this->request('POST', $this->apiUrl . '/scrape', $payload);
        } catch (\Exception $e) {
            // Gracefully handle invalid/unavailable 2Captcha
            if ($useTwocaptcha === true && $this->isTwocaptchaError($e->getMessage()) === true) {
                $this->invalidateTwocaptcha($e->getMessage());
                unset($payload['twocaptchaApiKey']);
                $this->log('info', 'Retrying request without 2Captcha...');
                $response = $this->request('POST', $this->apiUrl . '/scrape', $payload);
            } else {
                throw $e;
            }
        }

        $tier = $response['tier'] ?? null;
        $totalMs = $response['totalMs'] ?? null;
        $cookiesCount = isset($response['cookies']) === true ? count((array)$response['cookies']) : 0;
        $sessionCached = $response['sessionCached'] ?? false;

        $this->log('info', 'Trawl scrape completed', [
            'url' => $url,
            'tier' => $tier,
            'total_ms' => $totalMs,
            'cookies_count' => $cookiesCount,
            'session_cached' => $sessionCached,
            'has_html' => isset($response['html']) === true,
        ]);

        if (isset($response['html']) === false) {
            throwServerException('Trawl did not return HTML content. Response: ' . json_encode($response));
        }

        return (string)$response['html'];
    }

    /**
     * Detects if an error message indicates a 2Captcha-related failure.
     */
    private function isTwocaptchaError(string $message): bool
    {
        $patterns = [
            'twocaptcha',
            '2captcha',
            'captcha solver',
            'captcha provider',
            'invalid api key',
            'api key invalid',
            'key not found',
            'insufficient balance',
        ];

        foreach ($patterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throwServerException('Failed to initialize cURL');
        }

        $curlHeaders = array_merge(['Content-Type: application/json'], $headers);

        if ($method === 'POST') {
            $jsonData = json_encode($payload, JSON_THROW_ON_ERROR);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonData,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
        } else {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false || ($httpCode !== 200 && $httpCode !== 201)) {
            $this->log('error', 'HTTP failed', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error
            ]);
            throwServerException("HTTP {$httpCode}: {$error}");
        }

        try {
            $result = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->log('error', 'Invalid JSON response from Trawl', [
                'error' => $e->getMessage(),
                'response' => is_string($response) === true ? substr($response, 0, 500) : ''
            ]);
            throwServerException('Invalid JSON response: ' . $e->getMessage());
        }

        if (isset($result['error']) === true && $result['error'] === true) {
            $this->log('error', 'Trawl API error', [
                'message' => $result['message'] ?? 'Unknown',
                'response' => $result
            ]);
            throwServerException('API error: ' . ($result['message'] ?? 'Unknown'));
        }

        return $result;
    }

    public function getBinary(string $url, array $options = []): array
    {
        $this->log('info', "Fetching binary {$url} via Trawl");

        if ($this->useForwardProxy === true) {
            return $this->getBinaryViaForwardProxy($url, $options);
        }

        $payload = [
            'url' => $url,
            'maxTimeout' => $options['timeout'] ?? 180000,
            'maxTier' => $options['max_tier'] ?? 4,
            'solveEmbeddedCaptchas' => false,
        ];

        $cookies = $options['cookies'] ?? [];
        if ($cookies !== []) {
            $payload['cookies'] = $cookies;
        }

        if (isset($options['session_id']) === true && $options['session_id'] !== '') {
            $payload['sessionId'] = $options['session_id'];
        }

        $response = $this->request('POST', $this->apiUrl . '/scrape', $payload);

        if (isset($response['html']) === false) {
            throwServerException('Trawl did not return content');
        }

        $body = (string)$response['html'];

        $urlPath = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo(is_string($urlPath) === true ? $urlPath : '', PATHINFO_EXTENSION);
        $type = 'application/octet-stream';

        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
        ];

        if (isset($mimeMap[strtolower($extension)]) === true) {
            $type = $mimeMap[strtolower($extension)];
        }

        return ['body' => $body, 'type' => $type];
    }

    private function getBinaryViaForwardProxy(string $url, array $options): array
    {
        $proxyUrl = "http://{$this->forwardProxyHost}:{$this->forwardProxyPort}";

        $ch = curl_init($url);
        if ($ch === false) {
            throwServerException('Failed to initialize cURL for forward proxy');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => $proxyUrl,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $headers = $options['headers'] ?? [];
        if ($headers !== []) {
            $curlHeaders = [];
            foreach ($headers as $key => $value) {
                $curlHeaders[] = "{$key}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if ($response === false || $httpCode !== 200) {
            $this->log('error', 'Forward proxy failed', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error
            ]);
            throwServerException("Forward proxy failed for {$url}: HTTP {$httpCode} - {$error}");
        }

        $type = is_string($contentType) === true ? trim(explode(';', $contentType)[0]) : 'application/octet-stream';

        $this->log('info', 'Binary fetched via forward proxy', [
            'url' => $url,
            'http_code' => $httpCode,
            'content_type' => $type,
            'size' => strlen((string)$response),
        ]);

        return ['body' => (string)$response, 'type' => $type];
    }
}
