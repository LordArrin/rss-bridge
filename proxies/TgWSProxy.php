<?php

declare(strict_types=1);

/**
 * TgWSProxy — SOCKS5 proxy for Telegram via tg-ws-proxy-docker.
 * 
 * Uses getContents() with CURLOPT_PROXY in the same format as legacy code:
 * "socks5h://user:pass@host:port" — this works with curl-impersonate.
 */
class TgWSProxy extends ProxyAbstract
{
    private const TG_HOSTS_PATTERN = '/\.(?:t\.me|telegram\.org|telesco\.pe|telegram\.me)$/i';

    private ?string $proxyUrl = null;

    protected function initialize(): void
    {
        $socksUrl = $this->config['socks_url'] ?? '';
        
        // If socks_url already contains full URL with credentials, use as-is
        if (preg_match('#^socks5h?://#', $socksUrl)) {
            $this->proxyUrl = $socksUrl;
        } else {
            // Build URL from separate fields
            $user = $this->config['socks_user'] ?? '';
            $pass = $this->config['socks_pass'] ?? '';
            $auth = '';
            
            if ($user !== '' && $pass !== '') {
                $auth = $user . ':' . $pass . '@';
            } elseif ($user !== '') {
                $auth = $user . '@';
            }
            
            // Extract host:port from socks_url if it's just host:port
            $hostPort = $socksUrl;
            if (preg_match('#(?:socks5h?://)?([^/]+)$#', $socksUrl, $m)) {
                $hostPort = $m[1];
            }
            
            $this->proxyUrl = 'socks5h://' . $auth . $hostPort;
        }
        
        $this->log('info', sprintf(
            "TgWSProxy initialized: %s",
            preg_replace('#://([^:@]+):([^@]+)@#', '://***:***@', $this->proxyUrl ?? 'null')
        ));
    }

    public function getName(): string
    {
        return 'TgWS (SOCKS5)';
    }

    public function isAvailable(): bool
    {
        if (!$this->proxyUrl) {
            return false;
        }

        try {
            $opts = [
                CURLOPT_PROXY => $this->proxyUrl,
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ];
            
            getContents('https://t.me/', [], $opts);
            return true;
        } catch (\Throwable $e) {
            $this->log('warning', 'TgWS availability check failed: ' . $e->getMessage());
            return false;
        }
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $useProxy = $this->shouldUseProxy($url);
        
        $curlOpts = [
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? $this->config['connect_timeout'] ?? 30),
            CURLOPT_TIMEOUT        => (int)($options['timeout'] ?? $this->config['request_timeout'] ?? 120),
        ];
        
        if ($useProxy && $this->proxyUrl) {
            $curlOpts[CURLOPT_PROXY] = $this->proxyUrl;
        }
        
        try {
            return (string) getContents($url, [], $curlOpts);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                "TgWS request failed for %s: %s",
                $url,
                $e->getMessage()
            ));
        }
    }

    public function getBinary(string $url, array $options = []): array
    {
        $useProxy = $this->shouldUseProxy($url);
        
        $curlOpts = [
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? $this->config['connect_timeout'] ?? 30),
            CURLOPT_TIMEOUT        => (int)($options['timeout'] ?? $this->config['request_timeout'] ?? 120),
        ];
        
        if ($useProxy && $this->proxyUrl) {
            $curlOpts[CURLOPT_PROXY] = $this->proxyUrl;
        }
        
        try {
            /** @var \Response $response */
            $response = getContents($url, [], $curlOpts, true);
            
            $body = $response->getBody();
            $headers = $response->getHeaders();
            $contentType = $headers['content-type'][0] ?? 'application/octet-stream';
            $type = trim(explode(';', $contentType)[0]);
            
            if ($body === '' || $body === null) {
                throw new \RuntimeException("Empty response for {$url}");
            }
            
            return ['body' => $body, 'type' => $type];
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                "TgWS binary fetch failed for %s: %s",
                $url,
                $e->getMessage()
            ));
        }
    }

    private function shouldUseProxy(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        
        return (bool)preg_match(self::TG_HOSTS_PATTERN, $host);
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        throw new \RuntimeException('TgWSProxy does not use executeRequest()');
    }
}