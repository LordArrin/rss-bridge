<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * Memcached-based distributed cache with performance optimizations.
 * Uses persistent connections to avoid TCP handshake on every request.
 * Supports both TCP (host:port) and Unix socket connections.
 *
 * Implements Stale-while-revalidate pattern: data is kept in cache beyond
 * its fresh TTL to allow serving stale content when upstream fails.
 */
final class MemcachedCache implements CacheInterface
{
    private readonly \Logger $logger;
    private readonly \Memcached $conn;
    private readonly string $cachePrefix;

    /**
     * Default stale TTL: keep expired data for 7 days for fallback purposes.
     */
    private const DEFAULT_STALE_TTL = 604800;

    // Memcached result codes (numeric values for compatibility)
    private const RES_SUCCESS = 0;
    private const RES_NOTFOUND = 16;
    private const RES_SERVER_END = 2;  // Server connection failed
    private const RES_TIMEOUT = 5;     // Operation timed out
    private const RES_E2BIG = 3;       // Item too large
    private const RES_NOTSTORED = 15;  // Item not stored (conditional failure)

    public function __construct(\Logger $logger, string $host, int $port, string $socketPath = '')
    {
        $this->logger = $logger;

        // Determine connection type: Unix socket or TCP
        $isUnixSocket = (empty($socketPath) === false) || (str_starts_with($host, '/') === true);

        if ($isUnixSocket === true) {
            $socketPath = (empty($socketPath) === false) ? $socketPath : $host;
            $persistentId = 'rssbridge_memcached_unix_' . md5($socketPath);
        } else {
            $persistentId = 'rssbridge_memcached_tcp_' . $host . '_' . $port;
        }

        // Use persistent connection (shared across requests in same FPM worker)
        $this->conn = new \Memcached($persistentId);

        // Only add server if this is a new persistent connection
        if (count($this->conn->getServerList()) === 0) {
            if ($isUnixSocket === true) {
                // Unix socket connection (port must be 0)
                if ($this->conn->addServer($socketPath, 0) === false) {
                    throw new \Exception('Unable to add memcached server (unix socket: ' . $socketPath . ')');
                }
            } else {
                // TCP connection
                if ($this->conn->addServer($host, $port) === false) {
                    throw new \Exception('Unable to add memcached server (tcp: ' . $host . ':' . $port . ')');
                }
            }
        }

        // Performance optimizations
        $this->conn->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $this->conn->setOption(\Memcached::OPT_COMPRESSION, true);
        $this->conn->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        $this->conn->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 2000);  // 2 seconds connect timeout
        $this->conn->setOption(\Memcached::OPT_RETRY_TIMEOUT, 1);  // 1 second retry timeout
        $this->conn->setOption(\Memcached::OPT_SEND_TIMEOUT, 2000000);  // 2 seconds send timeout
        $this->conn->setOption(\Memcached::OPT_RECV_TIMEOUT, 2000000);  // 2 seconds receive timeout

        // TCP-specific optimization (not applicable to Unix socket)
        if ($isUnixSocket === false) {
            $this->conn->setOption(\Memcached::OPT_TCP_NODELAY, true);  // Disable Nagle's algorithm
        }

        // Prefix to avoid conflicts with other applications
        $this->cachePrefix = 'rssbridge:';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->getWithStale($key);

        return $cached['fresh'] ?? $default;
    }

    public function getWithStale(string $key): array
    {
        $cacheKey = $this->createCacheKey($key);
        $item = $this->conn->get($cacheKey);

        if ($this->conn->getResultCode() === self::RES_NOTFOUND) {
            return ['fresh' => null, 'stale' => null];
        }

        if ($item === false) {
            return ['fresh' => null, 'stale' => null];
        }

        // Handle legacy format (raw value without metadata wrapper)
        if (is_array($item) === false || array_key_exists('value', $item) === false) {
            // Legacy data: treat as fresh for backward compatibility
            return ['fresh' => $item, 'stale' => $item];
        }

        $now = time();
        $value = $item['value'] ?? null;
        $freshUntil = $item['fresh_until'] ?? 0;

        // fresh_until = 0 means "never expires" (always fresh)
        $isFresh = ($freshUntil === 0 || $freshUntil > $now);

        return [
            'fresh' => $isFresh === true ? $value : null,
            'stale' => $value,
        ];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $cacheKey = $this->createCacheKey($key);
        $now = time();

        // Calculate fresh period
        $freshUntil = $ttl === null ? 0 : $now + $ttl;

        // Calculate when the stale data should also expire
        $staleTtl = self::DEFAULT_STALE_TTL;
        $expiresAt = $ttl === null ? 0 : $freshUntil + $staleTtl;

        // Wrap value with metadata for Stale-while-revalidate
        $item = [
            'value'       => $value,
            'fresh_until' => $freshUntil,
            'expires_at'  => $expiresAt,
        ];

        // Memcached uses 0 to mean "never expire"
        $result = $this->conn->set($cacheKey, $item, $expiresAt);

        if ($result === false) {
            $resultCode = $this->conn->getResultCode();
            $resultMessage = $this->conn->getResultMessage();

            // Log with severity based on error type
            $logLevel = match ($resultCode) {
                self::RES_SERVER_END => 'error',
                self::RES_TIMEOUT => 'error',
                self::RES_E2BIG => 'warning',
                self::RES_NOTSTORED => 'debug',
                default => 'warning',
            };

            $this->logger->$logLevel('Failed to store an item in memcached', [
                'key'           => $cacheKey,
                'resultCode'    => $resultCode,
                'resultMessage' => $resultMessage,
            ]);
        }
    }

    public function delete(string $key): void
    {
        $this->conn->delete($this->createCacheKey($key));
    }

    public function clear(): void
    {
        // Cannot use flush() with prefix - need to delete by prefix
        // For safety, just flush the whole cache (rarely called)
        $this->conn->flush();
    }

    public function prune(): void
    {
        // Memcached manages expiration automatically
    }

    /**
     * Get statistics about the memcached server.
     */
    public function getStats(): array
    {
        return $this->conn->getStats();
    }

    private function createCacheKey(string $key): string
    {
        return $this->cachePrefix . hash('sha256', $key);
    }
}
