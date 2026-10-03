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

    /**
     * Hard protocol limit of memcached: a single item cannot exceed 1 MB.
     * This is NOT affected by the server's -I flag (which only controls slab
     * sizes below this ceiling). Anything larger must be chunked client-side.
     */
    private const MAX_ITEM_SIZE = 1048576;

    /**
     * Safety margin for key overhead and serialization growth.
     */
    private const CHUNK_SAFETY = 65536;

    /**
     * Maximum number of chunks per logical key (guard against runaway values).
     */
    private const MAX_CHUNKS = 128;

    private readonly int $chunkSize;
    private readonly int $maxChunks;

    /**
     * Parses size strings like "32M", "983040", "1G" into bytes.
     * Returns null when the value is empty or unparseable.
     */
    public static function parseSize(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $matches = [];
        if (preg_match('/^(\d+)\s*([KMG])?B?$/i', trim($value), $matches) !== 1) {
            return null;
        }

        $bytes = (int)$matches[1];
        $unit = strtoupper($matches[2] ?? '');
        $multipliers = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824];

        return $bytes * ($multipliers[$unit] ?? 1);
    }

    public function __construct(
        \Logger $logger,
        string $host,
        int $port,
        string $socketPath = '',
        ?int $itemSizeLimit = null,
        ?int $maxChunks = null
    ) {
        $this->logger = $logger;

        // Effective per-item limit: min(configured item_size_limit, 1MB hard cap)
        $effectiveLimit = self::MAX_ITEM_SIZE;
        if ($itemSizeLimit !== null && $itemSizeLimit > 0 && $itemSizeLimit < $effectiveLimit) {
            $effectiveLimit = $itemSizeLimit;
        }
        $this->chunkSize = max(65536, $effectiveLimit - self::CHUNK_SAFETY);
        $this->maxChunks = ($maxChunks !== null && $maxChunks > 0) ? $maxChunks : self::MAX_CHUNKS;

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

        // Fetch the metadata wrapper first: for chunked entries it contains
        // only a small list of chunk keys, so we avoid downloading (and
        // keeping in memory) the full payload when the entry is still fresh.
        $item = $this->conn->get($cacheKey);

        $resultCode = $this->conn->getResultCode();
        if ($resultCode !== self::RES_SUCCESS) {
            if ($resultCode !== self::RES_NOTFOUND) {
                // Server error/timeout: report as miss but log it so failures
                // are not silently indistinguishable from a cache miss.
                $this->logger->warning(sprintf(
                    'Memcached get failed (code %d: %s)',
                    $resultCode,
                    (string) $this->conn->getResultMessage()
                ));
            }
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
        $freshUntil = (int) ($item['fresh_until'] ?? 0);
        $isChunked = isset($item['chunks']) === true && is_array($item['chunks']) === true;

        // Fast path: non-chunked fresh entry - no payload reassembly needed.
        if ($isChunked === false && ($freshUntil === 0 || $freshUntil > $now)) {
            return ['fresh' => $value, 'stale' => $value];
        }

        // Chunked entry: reassemble payload from secondary keys (only reached
        // for stale reads or expired chunks).
        if ($isChunked === true) {
            $value = $this->readChunked($item['chunks']);

            if ($value === null) {
                // Some chunk was evicted or lost: treat the whole entry as a
                // miss instead of serving corrupt/truncated data.
                return ['fresh' => null, 'stale' => null];
            }

            try {
                $value = unserialize($value, ['allowed_classes' => true]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to unserialize chunked memcached entry: ' . $e->getMessage());
                return ['fresh' => null, 'stale' => null];
            }
        }

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

        // Absolute expiry timestamp for the memcached server. The server only
        // understands absolute unix timestamps, which keeps long-lived entries
        // (e.g. bridge metadata with a 30-day TTL) from being re-interpreted as
        // a relative TTL of ~1970 when it exceeds the 30-day boundary.
        $serverExpiresAt = match (true) {
            $ttl === null => 0, // 0 means "never expire" in memcached
            $freshUntil <= $now => 1, // expired immediately: store for 1 second
            default => $freshUntil + self::DEFAULT_STALE_TTL,
        };

        $storeValue = $value;
        $chunkKeys = [];

        // The memcached protocol caps a single item at 1 MB regardless of the
        // server's -I setting. Values larger than the effective limit are
        // serialized and split into chunks stored under secondary keys.
        // Chunks are stored as raw strings with compression disabled:
        // zlib on arbitrary byte slices can inflate instead of deflate, and
        // the payload is already compressed once at the metadata level.
        $encoded = @serialize($value);
        if ($encoded !== false && strlen($encoded) > $this->chunkSize) {
            $parts = str_split($encoded, $this->chunkSize);

            if (count($parts) > $this->maxChunks) {
                $this->logger->warning('Refusing to store oversized memcached item', [
                    'key'       => $cacheKey,
                    'size'      => strlen($encoded),
                    'maxChunks' => $this->maxChunks,
                ]);
                return;
            }

            $this->conn->setOption(\Memcached::OPT_COMPRESSION, false);
            $chunkFailed = false;
            foreach ($parts as $index => $part) {
                $chunkKey = $cacheKey . sprintf('#c%04d', $index);
                $chunkKeys[] = $chunkKey;

                if ($this->conn->set($chunkKey, $part, $serverExpiresAt) === false) {
                    $this->logStoreFailure($chunkKey, strlen($part));
                    $chunkFailed = true;
                    break;
                }
            }
            $this->conn->setOption(\Memcached::OPT_COMPRESSION, true);

            if ($chunkFailed === true) {
                foreach ($chunkKeys as $chunkKey) {
                    $this->conn->delete($chunkKey);
                }
                return;
            }

            // Metadata holds only the chunk key list, never the payload itself.
            $storeValue = [
                'value'  => null,
                'chunks' => $chunkKeys,
            ];
        }

        // Wrap value with metadata for Stale-while-revalidate.
        // 'expires_at' is informational only: reads decide freshness via
        // 'fresh_until', and actual removal is handled by memcached itself
        // using $serverExpiresAt above.
        $item = [
            'value'       => $storeValue,
            'fresh_until' => $freshUntil,
            'expires_at'  => $serverExpiresAt,
        ];

        if ($this->conn->set($cacheKey, $item, $serverExpiresAt) === false) {
            // Clean up orphaned chunks if the metadata write failed
            if ($chunkKeys !== []) {
                foreach ($chunkKeys as $chunkKey) {
                    $this->conn->delete($chunkKey);
                }
            }
            $this->logStoreFailure($cacheKey, strlen((string) @serialize($item)));
        }
    }

    public function delete(string $key): void
    {
        $cacheKey = $this->createCacheKey($key);

        // If this is a chunked entry, delete its chunks too
        $item = $this->conn->get($cacheKey);
        if (
            is_array($item) === true
            && array_key_exists('chunks', $item) === true
            && is_array($item['chunks']) === true
        ) {
            foreach ($item['chunks'] as $chunkKey) {
                $this->conn->delete($chunkKey);
            }
        }

        $this->conn->delete($cacheKey);
    }

    /**
     * Fetch all chunk keys via a single multi-get and concatenate payloads.
     *
     * @return string|null Reassembled payload or null if any chunk is missing
     */
    private function readChunked(array $chunkKeys): ?string
    {
        $values = $this->conn->getMulti($chunkKeys, \Memcached::GET_PRESERVE_KEYS);
        $resultCode = $this->conn->getResultCode();

        // Partial hits come back with RES_NOTFOUND while fully successful
        // multi-gets return RES_SUCCESS; anything else is a server error.
        if ($resultCode !== self::RES_SUCCESS && $resultCode !== self::RES_NOTFOUND) {
            $this->logger->warning(sprintf(
                'Memcached chunked get failed (code %d: %s)',
                $resultCode,
                (string) $this->conn->getResultMessage()
            ));
            return null;
        }

        if ($values === false || count($values) !== count($chunkKeys)) {
            // At least one chunk was evicted before the metadata expired
            return null;
        }

        $payload = '';
        foreach ($chunkKeys as $chunkKey) {
            if (array_key_exists($chunkKey, $values) === false || is_string($values[$chunkKey]) === false) {
                return null;
            }
            $payload .= $values[$chunkKey];
        }

        return $payload;
    }

    private function logStoreFailure(string $cacheKey, int $size): void
    {
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
            'size'          => $size,
            'resultCode'    => $resultCode,
            'resultMessage' => $resultMessage,
        ]);
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
