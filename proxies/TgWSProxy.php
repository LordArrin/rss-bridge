<?php

declare(strict_types=1);

class TgWSProxy extends ProxyAbstract
{
    private ?string $proxyUrl = null;

    protected function initialize(): void
    {
        $this->proxyUrl = $this->config['socks_url'] ?? null;
        
        $this->log('info', sprintf(
            "TgWSProxy initialized: proxy=%s",
            preg_replace('#://([^:@]+):([^@]+)@#', '://***:***@', $this->proxyUrl ?? 'null')
        ));
    }

    public function getName(): string
    {
        return 'TgWS (SOCKS5)';
    }

    public function isAvailable(): bool
    {
        return !empty($this->proxyUrl);
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $opts = [
            CURLOPT_CONNECTTIMEOUT => (int)($this->config['connect_timeout'] ?? 30),
            CURLOPT_TIMEOUT        => (int)($this->config['request_timeout'] ?? 120),
        ];
        
        if ($this->proxyUrl) {
            $opts[CURLOPT_PROXY] = $this->proxyUrl;
        }
        
        $this->log('info', sprintf(
            "TgWSProxy calling getContents(%s) with CURLOPT_PROXY=%s",
            $url,
            preg_replace('#://([^:@]+):([^@]+)@#', '://***:***@', $this->proxyUrl ?? 'null')
        ));
        
        try {
            $html = getContents($url, [], $opts);
            $this->log('info', sprintf("TgWSProxy got %d bytes for %s", strlen($html), $url));
            return (string)$html;
        } catch (\Throwable $e) {
            throw new \RuntimeException("TgWS request failed for {$url}: " . $e->getMessage());
        }
    }

    public function getBinary(string $url, array $options = []): array
    {
        $opts = [
            CURLOPT_CONNECTTIMEOUT => (int)($this->config['connect_timeout'] ?? 30),
            CURLOPT_TIMEOUT        => (int)($this->config['request_timeout'] ?? 120),
        ];
        
        if ($this->proxyUrl) {
            $opts[CURLOPT_PROXY] = $this->proxyUrl;
        }
        
        try {
            $response = getContents($url, [], $opts, true);
            
            $body = $response->getBody();
            $ct = $response->getHeaders()['content-type'][0] ?? 'application/octet-stream';
            $type = trim(explode(';', $ct)[0]);
            
            if ($body === '' || $body === null) {
                throw new \RuntimeException("Empty response for {$url}");
            }
            
            return ['body' => $body, 'type' => $type];
        } catch (\Throwable $e) {
            throw new \RuntimeException("TgWS binary fetch failed for {$url}: " . $e->getMessage());
        }
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        throw new \RuntimeException('TgWSProxy does not use executeRequest()');
    }
}