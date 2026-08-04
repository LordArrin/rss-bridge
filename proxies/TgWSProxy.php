<?php

declare(strict_types=1);

class TgWSProxy extends ProxyAbstract
{
    private const TG_HOSTS_PATTERN = '/\.(?:t\.me|telegram\.org|telesco\.pe|telegram\.me)$/i';

    private ?string $socksUrl = null;
    private ?string $socksUser = null;
    private ?string $socksPass = null;
    private int $connectTimeout = 30;
    private int $requestTimeout = 120;

    protected function initialize(): void
    {
        $this->socksUrl = $this->config['socks_url'] ?? null;
        $this->socksUser = !empty($this->config['socks_user']) ? $this->config['socks_user'] : null;
        $this->socksPass = !empty($this->config['socks_pass']) ? $this->config['socks_pass'] : null;
        $this->connectTimeout = (int)($this->config['connect_timeout'] ?? 30);
        $this->requestTimeout = (int)($this->config['request_timeout'] ?? 120);
        
        $this->timeout = $this->requestTimeout + 10;
        $this->maxRetries = (int)($this->config['retries'] ?? 3);
    }

    public function getName(): string
    {
        return 'TgWS (SOCKS5 via Cloudflare Worker)';
    }

    public function isAvailable(): bool
    {
        if (empty($this->socksUrl)) {
            return false;
        }

        try {
            $ch = curl_init('https://t.me/');
            curl_setopt_array($ch, $this->buildCurlOptions([
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]));
            
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            
            if ($errno !== 0 || $code >= 500) {
                $this->log('warning', "TgWS proxy unavailable: errno={$errno}, http={$code}");
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            $this->log('warning', 'TgWS availability check failed: ' . $e->getMessage());
            return false;
        }
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $useProxy = $this->shouldUseProxy($url);
        
        $curlOptions = $this->buildCurlOptions([
            CURLOPT_CONNECTTIMEOUT => (int)(($options['connect_timeout'] ?? $this->connectTimeout)),
            CURLOPT_TIMEOUT        => (int)(($options['timeout'] ?? $this->requestTimeout)),
        ], $useProxy);
        
        $headers = $options['headers'] ?? [];
        
        try {
            return (string) getContents($url, $headers, $curlOptions);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "TgWS request failed for {$url} (proxy=" . ($useProxy ? 'yes' : 'no') . "): " . 
                $e->getMessage()
            );
        }
    }

    public function getBinary(string $url, array $options = []): array
    {
        $useProxy = $this->shouldUseProxy($url);
        
        $curlOptions = $this->buildCurlOptions([
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? $this->connectTimeout),
            CURLOPT_TIMEOUT        => (int)($options['timeout'] ?? $this->requestTimeout),
        ], $useProxy);
        
        try {
            /** @var \Response $response */
            $response = getContents($url, [], $curlOptions, true);
            
            $body = $response->getBody();
            $headers = $response->getHeaders();
            $contentType = $headers['content-type'][0] ?? 'application/octet-stream';
            $type = trim(explode(';', $contentType)[0]);
            
            if ($body === '' || $body === null) {
                throw new \RuntimeException("Empty response for {$url}");
            }
            
            return ['body' => $body, 'type' => $type];
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "TgWS binary fetch failed for {$url}: " . $e->getMessage()
            );
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

    private function buildCurlOptions(array $extra = [], bool $useProxy = true): array
    {
        $options = $extra;
        
        if ($useProxy && $this->socksUrl) {
            $options[CURLOPT_PROXY] = $this->socksUrl;
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            
            if ($this->socksUser !== null && $this->socksPass !== null) {
                $options[CURLOPT_PROXYUSERPWD] = $this->socksUser . ':' . $this->socksPass;
            }
        }
        
        return $options;
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        throw new \RuntimeException('TgWSProxy does not use executeRequest()');
    }
}