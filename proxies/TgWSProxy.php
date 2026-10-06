<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

final class TgWSProxy extends ProxyAbstract
{
    private const MAX_BUDGET_SECONDS = 45;

    private const CONNECTION_ERRNOS = [
        \CURLE_COULDNT_CONNECT,      // 7
        \CURLE_OPERATION_TIMEDOUT,   // 28
        \CURLE_SSL_CONNECT_ERROR,    // 35
        \CURLE_RECV_ERROR,           // 56
        \CURLE_SEND_ERROR,           // 55
        \CURLE_GOT_NOTHING,          // 52
        \CURLE_PARTIAL_FILE,         // 18
        \CURLE_COULDNT_RESOLVE_PROXY, // 5
        \CURLE_COULDNT_RESOLVE_HOST,  // 6
    ];

    private const RETRYABLE_HTTP_CODES = [429, 500, 502, 503, 504];

    private ?string $proxyUrl = null;

    private ?\CurlHandle $handle = null;

    private const FATAL_ERROR_PATTERNS = [
        'port restricted',
        'port 443',
        'host unreachable',
        'command unsupported',
        'authentication failed',
        'access denied',
    ];

    private const RETRYABLE_ERROR_PATTERNS = [
        'timeout',
        'timed out',
        'connection reset',
        'connection refused',
        'connection failed',
        'could not connect',
        'network is unreachable',
        'temporary failure',
        'operation timed out',
        'empty response',
        'socket',
        'eof',
        'ssl',
    ];

    protected function initialize(): void
    {
        $this->proxyUrl = $this->config['socks_url'] ?? null;

        // Sensible defaults for a web request context: fail fast, retry cheap.
        // The old defaults (30s/120s x 3 retries = ~7 min worst case) exceeded
        // any realistic fpm request_terminate_timeout / nginx proxy_read_timeout.
        if (isset($this->config['connect_timeout']) === false) {
            $this->config['connect_timeout'] = 5;
        }
        if (isset($this->config['request_timeout']) === false) {
            $this->config['request_timeout'] = 20;
        }
        if (isset($this->config['retries']) === false) {
            $this->config['retries'] = 2;
        }
        if (isset($this->config['budget_seconds']) === false) {
            $this->config['budget_seconds'] = self::MAX_BUDGET_SECONDS;
        }
        if (isset($this->config['fallback_direct']) === false) {
            $this->config['fallback_direct'] = true;
        }

        $this->log('info', sprintf(
            'TgWSProxy initialized: proxy=%s, connect_timeout=%ds, request_timeout=%ds, retries=%d, budget=%ds, fallback_direct=%s',
            $this->maskProxyUrl($this->proxyUrl),
            (int) $this->config['connect_timeout'],
            (int) $this->config['request_timeout'],
            (int) $this->config['retries'],
            (int) $this->config['budget_seconds'],
            ($this->config['fallback_direct'] ?? false) === true ? 'yes' : 'no'
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

    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');

        if ($scheme === 'http') {
            $parsed['scheme'] = 'https';
            if (isset($parsed['port']) === true && $parsed['port'] === 80) {
                unset($parsed['port']);
            }
            return $this->buildUrl($parsed);
        }

        return $url;
    }

    private function buildUrl(array $parsed): string
    {
        $url = $parsed['scheme'] . '://';

        if (isset($parsed['user']) === true) {
            $url .= $parsed['user'];
            if (isset($parsed['pass']) === true) {
                $url .= ':' . $parsed['pass'];
            }
            $url .= '@';
        }

        $url .= $parsed['host'];

        if (isset($parsed['port']) === true) {
            $url .= ':' . $parsed['port'];
        }

        $url .= $parsed['path'] ?? '/';

        if (isset($parsed['query']) === true) {
            $url .= '?' . $parsed['query'];
        }

        if (isset($parsed['fragment']) === true) {
            $url .= '#' . $parsed['fragment'];
        }

        return $url;
    }

    private function assertPort443(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

        if ((int) $port !== 443) {
            throw new \RuntimeException(sprintf(
                'TgWSProxy only supports port 443 (got %d for %s). URL will be automatically upgraded to HTTPS if possible.',
                $port,
                $url
            ));
        }
    }

    private function getHandle(): \CurlHandle
    {
        if ($this->handle === null) {
            $handle = curl_init();

            if ($handle === false) {
                throw new \RuntimeException('Failed to initialize cURL handle');
            }

            $this->handle = $handle;
        }

        return $this->handle;
    }

    private function discardHandle(): void
    {
        // PHP 8.5 removed curl_close(): the handle is released as soon as
        // the last reference goes away, so dropping the property is enough.
        // (A function_exists() guard was used while targeting PHP 8.1-8.4;
        // with a hard "php": ">=8.5" requirement it is dead code.)
        $this->handle = null;
    }

    private function resetHandle(): \CurlHandle
    {
        $ch = $this->getHandle();
        curl_reset($ch);
        $this->setupBaseOptions($ch);
        return $ch;
    }

    private function setupBaseOptions(\CurlHandle $ch): void
    {
        $baseOptions = [
            CURLOPT_PROXYTYPE        => CURLPROXY_SOCKS5_HOSTNAME,
            CURLOPT_HTTP_VERSION     => CURL_HTTP_VERSION_1_1,
            CURLOPT_FRESH_CONNECT    => false,
            CURLOPT_FORBID_REUSE     => false,
            CURLOPT_TCP_KEEPALIVE    => 1,
            CURLOPT_TCP_KEEPIDLE     => 60,
            CURLOPT_TCP_KEEPINTVL    => 30,
            CURLOPT_ENCODING         => '',
            CURLOPT_FOLLOWLOCATION   => true,
            CURLOPT_MAXREDIRS        => 5,
            CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_NOSIGNAL         => true,
            CURLOPT_USERAGENT        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
        ];

        if ($this->proxyUrl !== null && $this->proxyUrl !== '') {
            $baseOptions[CURLOPT_PROXY] = $this->proxyUrl;
        }

        curl_setopt_array($ch, $baseOptions);
    }

    private function applyRequestOptions(
        \CurlHandle $ch,
        string $url,
        int $connectTimeout,
        int $requestTimeout,
        bool $includeHeaders
    ): void {
        $this->setupBaseOptions($ch);

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT        => $requestTimeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => $includeHeaders,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => [],
            CURLOPT_POSTFIELDS     => null,
        ];

        curl_setopt_array($ch, $options);
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $url = $this->normalizeUrl($url);

        try {
            $this->assertPort443($url);
        } catch (\RuntimeException $e) {
            $this->log('warning', $e->getMessage());
            if (($this->config['fallback_direct'] ?? false) === true) {
                $this->log('info', sprintf('Falling back to direct connection for %s', $url));
                return parent::fetchHtml($url, $options);
            }
            throw $e;
        }

        $connectTimeout = (int) ($this->config['connect_timeout'] ?? 5);
        $requestTimeout = (int) ($this->config['request_timeout'] ?? 20);
        $maxRetries = (int) ($this->config['retries'] ?? 2);
        $budget = min(
            max(1, (int) ($this->config['budget_seconds'] ?? self::MAX_BUDGET_SECONDS)),
            self::MAX_BUDGET_SECONDS
        );
        $deadline = microtime(true) + $budget;

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // Never start an attempt we cannot finish within the budget.
            $remaining = $deadline - microtime(true);
            if ($remaining <= 1.0) {
                $this->log('warning', sprintf(
                    'TgWSProxy budget (%ds) exhausted for %s after %d attempt(s)',
                    $budget,
                    $url,
                    $attempt - 1
                ));
                break;
            }

            try {
                $ch = $this->resetHandle();

                if ($attempt > 1) {
                    $baseDelay = min($attempt * 500000, 1500000);
                    $jitter = mt_rand(-100000, 100000);
                    $delayUs = max(200000, $baseDelay + $jitter);

                    // Cap the delay so it cannot eat the whole remaining budget.
                    $delayUs = min($delayUs, (int) (($remaining - 1.0) * 1000000));

                    $this->log('warning', sprintf(
                        'TgWSProxy retry %d/%d for %s (delay: %dms)',
                        $attempt,
                        $maxRetries,
                        $url,
                        (int) ($delayUs / 1000)
                    ));
                    usleep($delayUs);
                    $remaining = $deadline - microtime(true);
                }

                // Shrink per-attempt timeouts to what the budget still allows.
                $effConnect = max(1, min($connectTimeout, (int) floor($remaining)));
                $effRequest = max(1, min($requestTimeout, (int) floor($remaining)));

                $this->applyRequestOptions($ch, $url, $effConnect, $effRequest, false);

                $html = curl_exec($ch);

                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = (int) curl_errno($ch);

                if ($html === false || $curlErrno !== 0) {
                    throw new \RuntimeException(sprintf(
                        'cURL error %d: %s (HTTP %d)',
                        $curlErrno,
                        $curlError,
                        $httpCode
                    ), 0, $curlErrno);
                }

                if ($httpCode >= 400) {
                    throw new \RuntimeException(sprintf('HTTP %d for %s', $httpCode, $url));
                }

                if (is_string($html) === false || $html === '') {
                    throw new \RuntimeException('Empty response');
                }

                $this->log('debug', sprintf(
                    'TgWSProxy got %d bytes for %s [attempt %d, HTTP %d]',
                    strlen($html),
                    $url,
                    $attempt,
                    $httpCode
                ));

                return $html;
            } catch (\Throwable $e) {
                $lastException = $e;
                $errorMsg = $e->getMessage();
                $isRetryable = $this->isRetryableThrowable($e);

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

                // Connection-level errors poison the handle/socket: drop it.
                if ($this->isConnectionThrowable($e) === true) {
                    $this->discardHandle();
                }
            }
        }

        if (($this->config['fallback_direct'] ?? false) === true) {
            $this->log('warning', sprintf(
                'All proxy attempts failed for %s, falling back to direct connection',
                $url
            ));
            try {
                return parent::fetchHtml($url, $options);
            } catch (\Throwable $e) {
                $this->log('error', sprintf('Direct fallback also failed: %s', $e->getMessage()));
            }
        }

        throw new \RuntimeException(sprintf(
            'TgWS request failed for %s after %d attempts: %s',
            $url,
            $maxRetries,
            $lastException instanceof \Throwable ? $lastException->getMessage() : 'Unknown error'
        ));
    }

    public function getBinary(string $url, array $options = []): array
    {
        $url = $this->normalizeUrl($url);

        try {
            $this->assertPort443($url);
        } catch (\RuntimeException $e) {
            $this->log('warning', $e->getMessage());
            if (($this->config['fallback_direct'] ?? false) === true) {
                $this->log('info', sprintf('Falling back to direct connection for binary %s', $url));
                return parent::getBinary($url, $options);
            }
            throw $e;
        }

        $cacheTtl = $options['cache_ttl'] ?? 86400;
        $useCache = $options['use_cache'] ?? true;

        $cached = ['fresh' => null, 'stale' => null];

        if ($useCache === true && $this->cache instanceof \RSSBridge\Caches\CacheInterface) {
            $cacheKey = $this->buildCacheKey('binary_' . $url, $options);
            $cached = $this->cache->getWithStale($cacheKey);

            if ($this->isValidBinaryPayload($cached['fresh']) === true) {
                return $cached['fresh'];
            }
        }

        try {
            $payload = $this->doFetchBinaryInternal($url, $options);

            if (
                $useCache === true
                && $this->cache instanceof \RSSBridge\Caches\CacheInterface
                && $cacheTtl > 0
            ) {
                $cacheKey = $this->buildCacheKey('binary_' . $url, $options);
                $this->cache->set($cacheKey, $payload, $cacheTtl);
            }

            return $payload;
        } catch (\Throwable $e) {
            if (
                $useCache === true
                && $this->cache instanceof \RSSBridge\Caches\CacheInterface
                && $this->isValidBinaryPayload($cached['stale']) === true
            ) {
                $this->log('warning', sprintf(
                    'Returning stale binary cache for %s due to proxy error: %s',
                    $url,
                    $e->getMessage()
                ));
                return $cached['stale'];
            }

            throw $e;
        }
    }

    private function doFetchBinaryInternal(string $url, array $options): array
    {
        $connectTimeout = (int) ($this->config['connect_timeout'] ?? 5);
        // Binaries may be large; allow a bit more time than for HTML pages.
        $requestTimeout = min(
            (int) ($options['timeout'] ?? ($this->config['binary_request_timeout'] ?? 30)),
            60
        );
        $maxRetries = (int) ($this->config['retries'] ?? 2);
        $budget = min(
            max(1, (int) ($this->config['budget_seconds'] ?? self::MAX_BUDGET_SECONDS)),
            self::MAX_BUDGET_SECONDS
        );
        $deadline = microtime(true) + $budget;

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 1.0) {
                $this->log('warning', sprintf(
                    'TgWSProxy binary budget (%ds) exhausted for %s after %d attempt(s)',
                    $budget,
                    $url,
                    $attempt - 1
                ));
                break;
            }

            try {
                // curl_reset first: guarantees no HEADERFUNCTION / stale options
                // survive from a previous binary or html call on this handle.
                $ch = $this->resetHandle();

                $responseHeaders = '';
                $headerCallback = function ($ch, $header) use (&$responseHeaders): int {
                    $responseHeaders .= $header;
                    return strlen($header);
                };

                $effConnect = max(1, min($connectTimeout, (int) floor($remaining)));
                $effRequest = max(1, min($requestTimeout, (int) floor($remaining)));

                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_CONNECTTIMEOUT => $effConnect,
                    CURLOPT_TIMEOUT        => $effRequest,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER         => false,
                    CURLOPT_HEADERFUNCTION => $headerCallback,
                    CURLOPT_HTTPGET        => true,
                    CURLOPT_HTTPHEADER     => [],
                    CURLOPT_POSTFIELDS     => null,
                ]);

                if ($attempt > 1) {
                    $baseDelay = min($attempt * 500000, 1500000);
                    $jitter = mt_rand(-100000, 100000);
                    $delayUs = max(200000, $baseDelay + $jitter);
                    $delayUs = min($delayUs, (int) (($remaining - 1.0) * 1000000));

                    $this->log('warning', sprintf(
                        'TgWSProxy binary retry %d/%d for %s (delay: %dms)',
                        $attempt,
                        $maxRetries,
                        $url,
                        (int) ($delayUs / 1000)
                    ));
                    usleep($delayUs);
                }

                $body = curl_exec($ch);

                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = (int) curl_errno($ch);

                if ($body === false || $curlErrno !== 0) {
                    throw new \RuntimeException(sprintf(
                        'cURL error %d: %s (HTTP %d)',
                        $curlErrno,
                        $curlError,
                        $httpCode
                    ), 0, $curlErrno);
                }

                if ($httpCode >= 400) {
                    throw new \RuntimeException(sprintf('HTTP %d for %s', $httpCode, $url));
                }

                if (is_string($body) === false || $body === '') {
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

                return ['body' => $body, 'type' => $contentType];
            } catch (\Throwable $e) {
                $lastException = $e;
                $errorMsg = $e->getMessage();
                $isRetryable = $this->isRetryableThrowable($e);

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

                if ($this->isConnectionThrowable($e) === true) {
                    $this->discardHandle();
                }
            }
        }

        if (($this->config['fallback_direct'] ?? false) === true) {
            $this->log('warning', sprintf(
                'All proxy attempts failed for binary %s, falling back to direct connection',
                $url
            ));
            try {
                return parent::getBinary($url, $options);
            } catch (\Throwable $e) {
                $this->log('error', sprintf('Direct fallback also failed: %s', $e->getMessage()));
            }
        }

        throw new \RuntimeException(sprintf(
            'TgWS binary fetch failed for %s after %d attempts: %s',
            $url,
            $maxRetries,
            $lastException instanceof \Throwable ? $lastException->getMessage() : 'Unknown error'
        ));
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        throw new \RuntimeException('TgWSProxy does not use executeRequest()');
    }

    private function isRetryableThrowable(\Throwable $e): bool
    {
        $errorMsg = strtolower($e->getMessage());

        foreach (self::FATAL_ERROR_PATTERNS as $pattern) {
            if (str_contains($errorMsg, $pattern) === true) {
                return false;
            }
        }

        // 1. Real curl errno when we raised the exception ourselves.
        $errno = $e->getCode();
        if ($errno > 0 && $errno <= \CURLE_LAST_CODE) {
            return in_array($errno, self::CONNECTION_ERRNOS, true) === true;
        }

        // 2. Transient HTTP statuses extracted from "HTTP %d ..." messages.
        if (preg_match('/http (\d{3})/', $errorMsg, $m) === 1) {
            return in_array((int) $m[1], self::RETRYABLE_HTTP_CODES, true);
        }

        // 3. Substring fallback for non-curl exceptions (DOM parse, timeouts
        // thrown by parent classes etc.).
        foreach (self::RETRYABLE_ERROR_PATTERNS as $pattern) {
            if (str_contains($errorMsg, $pattern) === true) {
                return true;
            }
        }

        return false;
    }

    private function isConnectionThrowable(\Throwable $e): bool
    {
        $errno = $e->getCode();
        if ($errno > 0 && $errno <= \CURLE_LAST_CODE) {
            return in_array($errno, self::CONNECTION_ERRNOS, true);
        }

        $errorMsgLower = strtolower($e->getMessage());

        foreach (self::CONNECTION_ERRNOS as $code) {
            if (str_contains($errorMsgLower, 'curl error ' . $code) === true) {
                return true;
            }
        }

        foreach (['connection reset', 'connection refused', 'could not connect', 'timed out'] as $pattern) {
            if (str_contains($errorMsgLower, $pattern) === true) {
                return true;
            }
        }

        return false;
    }
}
