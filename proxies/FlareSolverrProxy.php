<?php

declare(strict_types=1);

class FlareSolverrProxy extends ProxyAbstract
{
    private ?string $apiUrl = null;
    private ?string $sessionName = null;
    private bool $sessionInitialized = false;
    private static array $sessionCache = [];

    protected function initialize(): void
    {
        $this->apiUrl = rtrim($this->config['url'] ?? '', '/') . '/v1';
        $this->sessionName = $this->config['session_name'] ?? 'rss_bridge_session';
    }

    public function getName(): string
    {
        return 'FlareSolverr';
    }

    /**
     * Checks availability via the sessions.list API
     */
    public function isAvailable(): bool
    {
        if (empty($this->config['url'])) {
            return false;
        }

        try {
            $response = $this->request('POST', $this->apiUrl, ['cmd' => 'sessions.list']);
            return ($response['status'] ?? '') === 'ok';
        } catch (\Exception $e) {
            $this->log('warning', 'FlareSolverr availability check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * FlareSolverr-specific validation
     */
    protected function validateResponse(string $response): bool
    {
        $challengeMarkers = [
            'Checking your browser',
            'cf-browser-verification',
            'challenge-platform',
            'Just a moment...',
            'Enable JavaScript',
        ];

        foreach ($challengeMarkers as $marker) {
            if (stripos($response, $marker) !== false) {
                $this->log('warning', "Detected Cloudflare challenge marker: {$marker}");
                return false;
            }
        }

        return parent::validateResponse($response);
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $domain = parse_url($url, PHP_URL_HOST) ?: 'localhost';
        
        $wait = $this->calculateWaitTime($url, $options);
        
        $payload = [
            'cmd' => 'request.get',
            'url' => $url,
            'maxTimeout' => $options['timeout'] ?? 180000,
            'wait' => $wait,
        ];

        $cookies = $options['cookies'] ?? [];
        
        if ($this->sessionName) {
            $payload['session'] = $this->sessionName;
            $this->ensureSession($domain, $cookies);
        }

        if (!empty($cookies)) {
            $payload['cookies'] = $cookies;
        }

        $response = $this->request('POST', $this->apiUrl, $payload);

        if (!isset($response['solution']['response'])) {
            throw new \RuntimeException('FlareSolverr did not return HTML content');
        }

        return (string)$response['solution']['response'];
    }

    /**
     * Smart timeout calculation:
     * - First request to a domain: wait longer (Cloudflare challenge)
     * - Subsequent requests: wait shorter (cookies already exist)
     */
    private function calculateWaitTime(string $url, array $options): int
    {
        $domain = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        
        if (isset($options['wait'])) {
            return $options['wait'];
        }

        $cacheKey = 'flaresolverr_domain_' . $domain;
        
        if (isset(self::$sessionCache[$cacheKey])) {
            $this->log('debug', "Domain {$domain} already visited, reducing wait time");
            return 2000;
        }

        $this->log('debug', "First request to domain {$domain}, using extended wait");
        self::$sessionCache[$cacheKey] = time();
        return 5000;
    }

    /**
     * Creates a session only once, remembers the state
     */
    private function ensureSession(string $domain, array $cookies): void
    {
        if (!$this->sessionName) {
            return;
        }

        $cacheKey = 'flaresolverr_session_' . $this->sessionName;
        
        if (isset(self::$sessionCache[$cacheKey]) && $this->sessionInitialized) {
            $this->log('debug', "Session {$this->sessionName} already initialized");
            return;
        }

        $response = $this->request('POST', $this->apiUrl, ['cmd' => 'sessions.list']);
        
        $sessionExists = false;
        foreach ($response['sessions'] ?? [] as $session) {
            if (($session['session'] ?? '') === $this->sessionName) {
                $sessionExists = true;
                break;
            }
        }

        if (!$sessionExists) {
            $this->log('info', "Creating new FlareSolverr session: {$this->sessionName}");
            
            $this->request('POST', $this->apiUrl, [
                'cmd' => 'sessions.create',
                'session' => $this->sessionName,
                'maxTimeout' => 300000,
                'cookies' => $cookies
            ]);
        }

        self::$sessionCache[$cacheKey] = time();
        $this->sessionInitialized = true;
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        
        $curlHeaders = array_merge(['Content-Type: application/json'], $headers);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => $method === 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $this->log('error', "HTTP request failed", [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error
            ]);
            throw new \RuntimeException("HTTP {$httpCode}: {$error}");
        }

        $result = json_decode((string)$response, true);
        
        if (($result['status'] ?? '') !== 'ok') {
            $this->log('error', "FlareSolverr API error", [
                'message' => $result['message'] ?? 'Unknown'
            ]);
            throw new \RuntimeException('API error: ' . ($result['message'] ?? 'Unknown'));
        }

        return $result;
    }
}