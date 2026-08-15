<?php

declare(strict_types=1);

class TgWSProxy extends ProxyAbstract
{
    private ?string $proxyUrl = null;
    private static ?\CurlHandle $persistentHandle = null;
    private static int $requestCount = 0;
    private static int $maxRequestsBeforeReset = 100;

    protected function initialize(): void
    {
        $this->proxyUrl = $this->config['socks_url'] ?? null;
        
        $this->log('info', sprintf(
            "TgWSProxy initialized: proxy=%s, max_retries=%d",
            preg_replace('#://([^:@]+):([^@]+)@#', '://***:***@', $this->proxyUrl ?? 'null'),
            (int)($this->config['retries'] ?? 3)
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

    private function getPersistentHandle(): \CurlHandle
    {
        if (self::$persistentHandle === null || self::$requestCount >= self::$maxRequestsBeforeReset) {
            if (self::$persistentHandle !== null) {
                curl_close(self::$persistentHandle);
            }
            self::$persistentHandle = curl_init();
            self::$requestCount = 0;
            
            curl_setopt(self::$persistentHandle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            curl_setopt(self::$persistentHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            
            curl_setopt(self::$persistentHandle, CURLOPT_FRESH_CONNECT, false);
            curl_setopt(self::$persistentHandle, CURLOPT_FORBID_REUSE, true); 
            
            curl_setopt(self::$persistentHandle, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt(self::$persistentHandle, CURLOPT_TCP_KEEPIDLE, 60);
            curl_setopt(self::$persistentHandle, CURLOPT_TCP_KEEPINTVL, 30);
            
            curl_setopt(self::$persistentHandle, CURLOPT_ENCODING, '');
            curl_setopt(self::$persistentHandle, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt(self::$persistentHandle, CURLOPT_MAXREDIRS, 5);
            
            curl_setopt(self::$persistentHandle, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt(self::$persistentHandle, CURLOPT_SSL_VERIFYHOST, 2);
            
            if ($this->proxyUrl) {
                curl_setopt(self::$persistentHandle, CURLOPT_PROXY, $this->proxyUrl);
            }
        }
        return self::$persistentHandle;
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $connectTimeout = (int)($this->config['connect_timeout'] ?? 15);
        $requestTimeout = (int)($this->config['request_timeout'] ?? 60);
        $maxRetries = (int)($this->config['retries'] ?? 3);
        
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $ch = $this->getPersistentHandle();
                
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, false);
                
                if ($attempt > 1) {
                    $this->log('warning', sprintf(
                        "TgWSProxy retry %d/%d for %s",
                        $attempt,
                        $maxRetries,
                        $url
                    ));
                    usleep(500000 * $attempt);
                }
                
                self::$requestCount++;
                
                $html = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                
                if ($html === false || $curlErrno !== 0) {
                    throw new \RuntimeException(sprintf(
                        "cURL error %d: %s (HTTP %d)",
                        $curlErrno,
                        $curlError,
                        $httpCode
                    ));
                }
                
                if ($httpCode >= 400) {
                    throw new \RuntimeException(sprintf("HTTP %d for %s", $httpCode, $url));
                }
                
                if (empty($html)) {
                    throw new \RuntimeException("Empty response");
                }
                
                $this->log('debug', sprintf(
                    "TgWSProxy got %d bytes for %s [attempt %d, HTTP %d]",
                    strlen($html),
                    $url,
                    $attempt,
                    $httpCode
                ));
                
                return (string)$html;
                
            } catch (\Throwable $e) {
                $lastException = $e;
                
                $errorMsg = $e->getMessage();
                $isRetryable = $this->isRetryableError($errorMsg);
                
                $this->log('warning', sprintf(
                    "TgWSProxy attempt %d/%d failed for %s: %s (retryable: %s)",
                    $attempt,
                    $maxRetries,
                    $url,
                    $errorMsg,
                    $isRetryable ? 'yes' : 'no'
                ));
                
                if (!$isRetryable || $attempt >= $maxRetries) {
                    break;
                }
                
                if ($this->isConnectionError($errorMsg)) {
                    self::$persistentHandle = null;
                }
            }
        }
        
        throw new \RuntimeException(sprintf(
            "TgWS request failed for %s after %d attempts: %s",
            $url,
            $maxRetries,
            $lastException?->getMessage() ?? 'Unknown error'
        ));
    }

    public function getBinary(string $url, array $options = []): array
    {
        $connectTimeout = (int)($this->config['connect_timeout'] ?? 15);
        $requestTimeout = (int)($this->config['request_timeout'] ?? 90);
        $maxRetries = (int)($this->config['retries'] ?? 3);
        
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $ch = $this->getPersistentHandle();
                
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, true);
                
                if ($attempt > 1) {
                    $this->log('warning', sprintf(
                        "TgWSProxy binary retry %d/%d for %s",
                        $attempt,
                        $maxRetries,
                        $url
                    ));
                    usleep(500000 * $attempt);
                }
                
                self::$requestCount++;
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                
                if ($response === false || $curlErrno !== 0) {
                    throw new \RuntimeException(sprintf(
                        "cURL error %d: %s (HTTP %d)",
                        $curlErrno,
                        $curlError,
                        $httpCode
                    ));
                }
                
                if ($httpCode >= 400) {
                    throw new \RuntimeException(sprintf("HTTP %d for %s", $httpCode, $url));
                }
                
                $headers = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);
                
                if (empty($body)) {
                    throw new \RuntimeException("Empty response");
                }
                
                $contentType = 'application/octet-stream';
                if (preg_match('/content-type:\s*([^\r\n]+)/i', $headers, $matches)) {
                    $contentType = trim(explode(';', $matches[1])[0]);
                }
                
                $this->log('debug', sprintf(
                    "TgWSProxy got %d bytes (%s) for %s [attempt %d, HTTP %d]",
                    strlen($body),
                    $contentType,
                    $url,
                    $attempt,
                    $httpCode
                ));
                
                return ['body' => $body, 'type' => $contentType];
                
            } catch (\Throwable $e) {
                $lastException = $e;
                
                $errorMsg = $e->getMessage();
                $isRetryable = $this->isRetryableError($errorMsg);
                
                $this->log('warning', sprintf(
                    "TgWSProxy binary attempt %d/%d failed for %s: %s (retryable: %s)",
                    $attempt,
                    $maxRetries,
                    $url,
                    $errorMsg,
                    $isRetryable ? 'yes' : 'no'
                ));
                
                if (!$isRetryable || $attempt >= $maxRetries) {
                    break;
                }
                
                if ($this->isConnectionError($errorMsg)) {
                    self::$persistentHandle = null;
                }
            }
        }
        
        throw new \RuntimeException(sprintf(
            "TgWS binary fetch failed for %s after %d attempts: %s",
            $url,
            $maxRetries,
            $lastException?->getMessage() ?? 'Unknown error'
        ));
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        throw new \RuntimeException('TgWSProxy does not use executeRequest()');
    }

    private function isRetryableError(string $errorMsg): bool
    {
        $retryablePatterns = [
            'timeout',
            'timed out',
            'connection reset',
            'connection refused',
            'connection failed',
            'could not connect',
            'network is unreachable',
            'temporary failure',
            'operation timed out',
            'curl error 7',
            'curl error 28',
            'curl error 56',
            'curl error 52',
            'socket',
            'eof',
        ];
        
        $errorMsgLower = strtolower($errorMsg);
        
        foreach ($retryablePatterns as $pattern) {
            if (str_contains($errorMsgLower, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    private function isConnectionError(string $errorMsg): bool
    {
        $connectionPatterns = [
            'connection reset',
            'connection refused',
            'connection failed',
            'could not connect',
            'curl error 7',
            'curl error 28',
            'curl error 56',
            'socket',
            'eof',
        ];
        
        $errorMsgLower = strtolower($errorMsg);
        
        foreach ($connectionPatterns as $pattern) {
            if (str_contains($errorMsgLower, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
}
