<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * Memcached-based distributed cache with performance optimizations.
 * Uses persistent connections to avoid TCP handshake on every request.
 */
final class MemcachedCache implements CacheInterface
{
    private readonly \Logger $logger;
    private readonly \Memcached $conn;
    private readonly string $cachePrefix;

    public function __construct(\Logger $logger, string $host, int $port)
    {
        $this->logger = $logger;

        // Use persistent connection (shared across requests in same FPM worker)
        $persistentId = 'rssbridge_memcached_' . $host . '_' . $port;
        $this->conn = new \Memcached($persistentId);

        // Only add server if this is a new persistent connection
        if (count($this->conn->getServerList()) === 0) {
            if (!$this->conn->addServer($host, $port)) {
                throw new \Exception('Unable to add memcached server');
            }
        }

        // Performance optimizations
        $this->conn->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $this->conn->setOption(\Memcached::OPT_COMPRESSION, true);
        $this->conn->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        $this->conn->setOption(\Memcached::OPT_TCP_NODELAY, true);  // Disable Nagle's algorithm
        $this->conn->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 1000);  // 1 second connect timeout
        $this->conn->setOption(\Memcached::OPT_RETRY_TIMEOUT, 1);  // 1 second retry timeout
        $this->conn->setOption(\Memcached::OPT_SEND_TIMEOUT, 500000);  // 500ms send timeout
        $this->conn->setOption(\Memcached::OPT_RECV_TIMEOUT, 500000);  // 500ms receive timeout

        // Prefix to avoid conflicts with other applications
        $this->cachePrefix = 'rssbridge:';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->conn->get($this->createCacheKey($key));

        if ($this->conn->getResultCode() === \Memcached::RES_NOTFOUND) {
            return $default;
        }

        if ($value === false) {
            return $default;
        }

        return $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $expiration = $ttl === null ? 0 : time() + $ttl;
        $cacheKey = $this->createCacheKey($key);

        $result = $this->conn->set($cacheKey, $value, $expiration);

        if ($result === false) {
            $this->logger->warning('Failed to store an item in memcached', [
                'key'           => $cacheKey,
                'resultCode'    => $this->conn->getResultCode(),
                'resultMessage' => $this->conn->getResultMessage(),
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
