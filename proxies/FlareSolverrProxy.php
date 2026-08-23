<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

final class FlareSolverrProxy extends ProxyAbstract
{
    private ?string $apiUrl = null;
    private ?string $sessionName = null;

    private const SESSION_FLAG_PREFIX = 'flaresolverr_session_created_';
    private const SESSION_FLAG_TTL = 86400;

    protected function initialize(): void
    {
        $this->apiUrl = rtrim($this->config['url'] ?? '', '/') . '/v1';
        $this->sessionName = $this->config['session_name'] ?? 'rss_bridge_session';
    }

    public function getName(): string
    {
        return 'FlareSolverr';
    }

    public function isAvailable(): bool
    {
        if (empty($this->config['url']) === true) {
            return false;
        }

        try {
            $response = $this->request('POST', $this->apiUrl, ['cmd' => 'sessions.list']);
            return ($response['status'] ?? '') === 'ok';
        } catch (\Exception $e) {
            $this->log('warning', 'FlareSolverr unavailable: ' . $e->getMessage());
            return false;
        }
    }

    protected function validateResponse(string $response): bool
    {
        $markers = [
            'Checking your browser',
            'cf-browser-verification',
            'challenge-platform',
            'Just a moment...',
            'Enable JavaScript',
        ];

        foreach ($markers as $marker) {
            if (stripos($response, $marker) !== false) {
                $this->log('warning', "Cloudflare challenge detected: {$marker}");
                return false;
            }
        }

        return parent::validateResponse($response);
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $parsedHost = parse_url($url, PHP_URL_HOST);
        $domain = (bool) $parsedHost === true ? $parsedHost : 'localhost';
        $wait = $this->calculateWaitTime($url, $options);

        $payload = [
            'cmd' => 'request.get',
            'url' => $url,
            'maxTimeout' => $options['timeout'] ?? 180000,
            'wait' => $wait,
        ];

        $cookies = $options['cookies'] ?? [];

        if ((bool) $this->sessionName === true) {
            $payload['session'] = $this->sessionName;
            $this->ensureSession($domain, $cookies);
        }

        if (empty($cookies) === false) {
            $payload['cookies'] = $cookies;
        }

        $response = $this->request('POST', $this->apiUrl, $payload);

        if (isset($response['solution']['response']) === false) {
            throw new \RuntimeException('FlareSolverr did not return HTML content');
        }

        return (string)$response['solution']['response'];
    }

    private function calculateWaitTime(string $url, array $options): int
    {
        if (isset($options['wait']) === true) {
            return (int)$options['wait'];
        }

        $parsedHost = parse_url($url, PHP_URL_HOST);
        $domain = (bool) $parsedHost === true ? $parsedHost : 'unknown';

        if ((bool) $this->cache === true) {
            $cacheKey = 'flaresolverr_domain_visited_' . md5($domain);
            if ((bool) $this->cache->get($cacheKey) === true) {
                $this->log('debug', "Domain {$domain} already visited, using short wait");
                return 2000;
            }
            $this->cache->set($cacheKey, time(), 3600);
        }

        $this->log('debug', "First request to {$domain}, using extended wait");
        return 5000;
    }

    private function ensureSession(string $domain, array $cookies): void
    {
        if ((bool) $this->sessionName === false) {
            return;
        }

        if ((bool) $this->cache === true) {
            $cacheKey = self::SESSION_FLAG_PREFIX . md5($this->sessionName);
            if ((bool) $this->cache->get($cacheKey) === true) {
                $this->log('debug', "Session {$this->sessionName} already created (cached)");
                return;
            }
        }

        $response = $this->request('POST', $this->apiUrl, ['cmd' => 'sessions.list']);

        $sessionExists = false;
        foreach ($response['sessions'] ?? [] as $session) {
            if (($session['session'] ?? '') === $this->sessionName) {
                $sessionExists = true;
                break;
            }
        }

        if ($sessionExists === false) {
            $this->log('info', "Creating session: {$this->sessionName}");

            $this->request('POST', $this->apiUrl, [
                'cmd' => 'sessions.create',
                'session' => $this->sessionName,
                'maxTimeout' => 300000,
                'cookies' => $cookies
            ]);
        } else {
            $this->log('debug', "Session {$this->sessionName} already exists");
        }

        if ((bool) $this->cache === true) {
            $cacheKey = self::SESSION_FLAG_PREFIX . md5($this->sessionName);
            $this->cache->set($cacheKey, time(), self::SESSION_FLAG_TTL);
        }
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

        if ($response === false || $httpCode !== 200) {
            $this->log('error', 'HTTP failed', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error
            ]);
            throw new \RuntimeException("HTTP {$httpCode}: {$error}");
        }

        $result = json_decode((string)$response, true);

        if (($result['status'] ?? '') !== 'ok') {
            $this->log('error', 'FlareSolverr API error', [
                'message' => $result['message'] ?? 'Unknown'
            ]);
            throw new \RuntimeException('API error: ' . ($result['message'] ?? 'Unknown'));
        }

        return $result;
    }

    /**
     * Fetches binary content via FlareSolverr.
     *
     * @return array{body: string, type: string}
     */
    public function getBinary(string $url, array $options = []): array
    {
        $this->log('info', "Fetching binary {$url} via FlareSolverr");

        $payload = [
            'cmd' => 'request.get',
            'url' => $url,
            'maxTimeout' => $options['timeout'] ?? 180000,
            'wait' => $options['wait'] ?? 5000,
        ];

        if ((bool) $this->sessionName === true) {
            $payload['session'] = $this->sessionName;
        }

        $response = $this->request('POST', $this->apiUrl, $payload);

        if (isset($response['solution']['response']) === false) {
            throw new \RuntimeException('FlareSolverr did not return content');
        }

        $body = (string)$response['solution']['response'];

        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
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
}
