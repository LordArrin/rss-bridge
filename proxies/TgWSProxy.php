<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

final class TgWSProxy extends ProxyAbstract
{
    private ?string $proxyUrl = null;
    private static ?\CurlHandle $persistentHandle = null;
    private static int $requestCount = 0;
    private static int $maxRequestsBeforeReset = 100;

    protected function initialize(): void
    {
        $this->proxyUrl = $this->config['socks_url'] ?? null;

        $this->log('info', sprintf(
            'TgWSProxy initialized: proxy=%s, max_retries=%d',
            $this->maskProxyUrl($this->proxyUrl),
            (int)($this->config['retries'] ?? 3)
        ));
    }

    public function getName(): string
    {
        return 'TgWS (SOCKS5)';
    }

    public function isAvailable(): bool
    {
        return empty($this->proxyUrl) === false;
    }

    /**
     * Safely masks credentials in proxy URL using parse_url instead of regex
     */
    private function maskProxyUrl(?string $url): string
    {
        if ($url === null) {
            return 'null';
        }

        $parsed = parse_url($url);
        if ($parsed === false || isset($parsed['host']) === false) {
            return '***';
        }

        $masked = ($parsed['scheme'] ?? 'socks5') . '://';
        if (isset($parsed['user']) === true) {
            $masked .= '***:***@';
        }
        $masked .= $parsed['host'];
        if (isset($parsed['port']) === true) {
            $masked .= ':' . $parsed['port'];
        }

        return $masked;
    }

    private function getPersistentHandle(): \CurlHandle
    {
        if (self::$persistentHandle === null || self::$requestCount >= self::$maxRequestsBeforeReset) {
            if (self::$persistentHandle !== null) {
                curl_reset(self::$persistentHandle);
            } else {
                self::$persistentHandle = curl_init();
                if (self::$persistentHandle === false) {
                    throw new \RuntimeException('Failed to initialize cURL handle');
                }
            }
            self::$requestCount = 0;
            $this->setupBaseOptions(self::$persistentHandle);
        }

        return self::$persistentHandle;
    }

    /**
     * Sets up base cURL options that apply to all requests using this handle.
     * Called after curl_reset() to ensure consistent state.
     */
    private function setupBaseOptions(\CurlHandle $ch): void
    {
        $baseOptions = [
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 60,
            CURLOPT_TCP_KEEPINTVL => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
        ];

        if ($this->proxyUrl !== null && $this->proxyUrl !== '') {
            $baseOptions[CURLOPT_PROXY] = $this->proxyUrl;
        }

        curl_setopt_array($ch, $baseOptions);
    }

    /**
     * Applies request-specific options to the handle, resetting any previous state.
     * Must be called after getPersistentHandle() which ensures base options are set.
     */
    private function applyRequestOptions(\CurlHandle $ch, string $url, int $connectTimeout, int $requestTimeout, bool $includeHeaders): void
    {
        curl_reset($ch);
        $this->setupBaseOptions($ch);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => $includeHeaders,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [],
            CURLOPT_POSTFIELDS => null,
        ];

        curl_setopt_array($ch, $options);
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
                $this->applyRequestOptions($ch, $url, $connectTimeout, $requestTimeout, false);

                if ($attempt > 1 === true) {
                    $delay = min($attempt * 500000, 2000000);
                    $this->log('warning', sprintf(
                        'TgWSProxy retry %d/%d for %s (delay: %dms)',
                        $attempt,
                        $maxRetries,
                        $url,
                        (int)($delay / 1000)
                    ));
                    usleep($delay);
                }

                self::$requestCount++;

                $html = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);

                if ($html === false || $curlErrno !== 0) {
                    throw new \RuntimeException(sprintf(
                        'cURL error %d: %s (HTTP %d)',
                        $curlErrno,
                        $curlError,
                        $httpCode
                    ));
                }

                if ($httpCode >= 400) {
                    throw new \RuntimeException(sprintf('HTTP %d for %s', $httpCode, $url));
                }

                if (empty($html) === true) {
                    throw new \RuntimeException('Empty response');
                }

                $this->log('debug', sprintf(
                    'TgWSProxy got %d bytes for %s [attempt %d, HTTP %d]',
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
                    'TgWSProxy attempt %d/%d failed for %s: %s (retryable: %s)',
                    $attempt,
                    $maxRetries,
                    $url,
                    $errorMsg,
                    $isRetryable === true ? 'yes' : 'no'
                ));

                if ($isRetryable === false || $attempt >= $maxRetries) {
                    break;
                }

                if ($this->isConnectionError($errorMsg) === true) {
                    self::$persistentHandle = null;
                    self::$requestCount = 0;
                }
            }
        }

        throw new \RuntimeException(sprintf(
            'TgWS request failed for %s after %d attempts: %s',
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

                // Reset handle and set base + request options
                curl_reset($ch);
                $this->setupBaseOptions($ch);

                $responseHeaders = '';
                $headerCallback = function ($ch, $header) use (&$responseHeaders) {
                    $responseHeaders .= $header;
                    return strlen($header);
                };

                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                    CURLOPT_TIMEOUT => $requestTimeout,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => false,
                    CURLOPT_HEADERFUNCTION => $headerCallback,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPGET => true,
                    CURLOPT_HTTPHEADER => [],
                    CURLOPT_POSTFIELDS => null,
                ]);

                if ($attempt > 1 === true) {
                    $delay = min($attempt * 500000, 2000000);
                    $this->log('warning', sprintf(
                        'TgWSProxy binary retry %d/%d for %s (delay: %dms)',
                        $attempt,
                        $maxRetries,
                        $url,
                        (int)($delay / 1000)
                    ));
                    usleep($delay);
                }

                self::$requestCount++;

                $body = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);

                if ($body === false || $curlErrno !== 0) {
                    throw new \RuntimeException(sprintf(
                        'cURL error %d: %s (HTTP %d)',
                        $curlErrno,
                        $curlError,
                        $httpCode
                    ));
                }

                if ($httpCode >= 400) {
                    throw new \RuntimeException(sprintf('HTTP %d for %s', $httpCode, $url));
                }

                if (empty($body) === true) {
                    throw new \RuntimeException('Empty response');
                }

                $contentType = 'application/octet-stream';
                if (preg_match('/content-type:\s*([^\r\n]+)/i', $responseHeaders, $matches) === 1) {
                    $contentType = trim(explode(';', $matches[1])[0]);
                }

                $this->log('debug', sprintf(
                    'TgWSProxy got %d bytes (%s) for %s [attempt %d, HTTP %d]',
                    strlen($body),
                    $contentType,
                    $url,
                    $attempt,
                    $httpCode
                ));

                return ['body' => (string)$body, 'type' => $contentType];
            } catch (\Throwable $e) {
                $lastException = $e;

                $errorMsg = $e->getMessage();
                $isRetryable = $this->isRetryableError($errorMsg);

                $this->log('warning', sprintf(
                    'TgWSProxy binary attempt %d/%d failed for %s: %s (retryable: %s)',
                    $attempt,
                    $maxRetries,
                    $url,
                    $errorMsg,
                    $isRetryable === true ? 'yes' : 'no'
                ));

                if ($isRetryable === false || $attempt >= $maxRetries) {
                    break;
                }

                if ($this->isConnectionError($errorMsg) === true) {
                    self::$persistentHandle = null;
                    self::$requestCount = 0;
                }
            }
        }

        throw new \RuntimeException(sprintf(
            'TgWS binary fetch failed for %s after %d attempts: %s',
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
            'ssl',
        ];

        $errorMsgLower = strtolower($errorMsg);

        foreach ($retryablePatterns as $pattern) {
            if (str_contains($errorMsgLower, $pattern) === true) {
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
            'curl error 35',
            'curl error 56',
            'socket',
            'eof',
            'ssl',
        ];

        $errorMsgLower = strtolower($errorMsg);

        foreach ($connectionPatterns as $pattern) {
            if (str_contains($errorMsgLower, $pattern) === true) {
                return true;
            }
        }

        return false;
    }
}
