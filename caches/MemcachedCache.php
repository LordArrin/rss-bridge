<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

use RSSBridge\Logger\Logger;

/**
 * Memcached-based distributed cache with performance optimizations.
 * Uses persistent connections to avoid TCP handshake on every request.
 * Supports both TCP (host:port) and Unix socket connections.
 *
 * Implements Stale-while-revalidate pattern: data is kept in cache beyond
 * its fresh TTL to allow serving stale content when upstream fails.
 *
 * Uses JSON serialization via AbstractCache to avoid Object Injection vulnerabilities.
 * Large values are automatically chunked to respect memcached's 1MB item size limit.
 */
final class MemcachedCache extends AbstractCache
{
    private readonly Logger $logger;
    private readonly \Memcached $conn;
    private readonly string $cachePrefix;
    private readonly bool $allowFlush;

    /** @var array<string, string> */
    private array $lockTokens = [];

    // Memcached result codes
    private const RES_SUCCESS = \Memcached::RES_SUCCESS;
    private const RES_NOTFOUND = \Memcached::RES_NOTFOUND;
    private const RES_NOTSTORED = \Memcached::RES_NOTSTORED;
    private const RES_DATA_EXISTS = \Memcached::RES_DATA_EXISTS;
    private const RES_E2BIG = \Memcached::RES_E2BIG;
    private const RES_TIMEOUT = \Memcached::RES_TIMEOUT;
    private const RES_SERVER_ERROR = \Memcached::RES_SERVER_ERROR;
    private const RES_CONNECTION_FAILURE = \Memcached::RES_CONNECTION_FAILURE;
    private const RES_HOST_LOOKUP_FAILURE = \Memcached::RES_HOST_LOOKUP_FAILURE;

    /**
     * Hard protocol limit of memcached: a single item cannot exceed 1 MB.
     * This is NOT affected by the server's -I flag (which only controls slab
     * sizes below this ceiling). Anything larger must be chunked client-side.
     */
    private const MAX_ITEM_SIZE = 1048576;

    /**
     * Safety margin for key overhead and JSON encoding growth.
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
        Logger $logger,
        string $host,
        int $port,
        string $socketPath = '',
        ?int $itemSizeLimit = null,
        ?int $maxChunks = null,
        bool $allowFlush = false
    ) {
        $this->logger = $logger;
        $this->allowFlush = $allowFlush;

        $effectiveLimit = self::MAX_ITEM_SIZE;
        if ($itemSizeLimit !== null && $itemSizeLimit > 0 && $itemSizeLimit < $effectiveLimit) {
            $effectiveLimit = $itemSizeLimit;
        }
        $this->chunkSize = max(65536, $effectiveLimit - self::CHUNK_SAFETY);
        $this->maxChunks = ($maxChunks !== null && $maxChunks > 0) ? $maxChunks : self::MAX_CHUNKS;

        $isUnixSocket = ($socketPath !== '') || (str_starts_with($host, '/') === true);

        if ($isUnixSocket === true) {
            $socketPath = ($socketPath !== '') ? $socketPath : $host;
            $persistentId = 'rssbridge_memcached_unix_' . md5($socketPath);
        } else {
            $persistentId = 'rssbridge_memcached_tcp_' . $host . '_' . $port;
        }

        $this->conn = new \Memcached($persistentId);

        if (count($this->conn->getServerList()) === 0) {
            if ($isUnixSocket === true) {
                if ($this->conn->addServer($socketPath, 0) === false) {
                    throw new \Exception('Unable to add memcached server (unix socket: ' . $socketPath . ')');
                }
            } else {
                if ($this->conn->addServer($host, $port) === false) {
                    throw new \Exception('Unable to add memcached server (tcp: ' . $host . ':' . $port . ')');
                }
            }
        }

        $this->conn->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $this->conn->setOption(\Memcached::OPT_COMPRESSION, true);
        $this->conn->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        $this->conn->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 2000);
        $this->conn->setOption(\Memcached::OPT_RETRY_TIMEOUT, 1);
        $this->conn->setOption(\Memcached::OPT_SEND_TIMEOUT, 2000000);
        $this->conn->setOption(\Memcached::OPT_RECV_TIMEOUT, 2000000);

        if ($isUnixSocket === false) {
            $this->conn->setOption(\Memcached::OPT_TCP_NODELAY, true);
        }

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

        $resultCode = $this->conn->getResultCode();
        if ($resultCode !== self::RES_SUCCESS) {
            if ($resultCode !== self::RES_NOTFOUND) {
                $this->logger->warning(sprintf(
                    'Memcached get failed (code %d: %s)',
                    $resultCode,
                    $this->conn->getResultMessage()
                ));
            }
            return ['fresh' => null, 'stale' => null];
        }

        if ($item === false) {
            return ['fresh' => null, 'stale' => null];
        }

        if (is_array($item) === false || array_key_exists('value', $item) === false) {
            $this->logger->info(sprintf('Discarding invalid cache entry for key "%s"', $key));
            $this->delete($key);
            return ['fresh' => null, 'stale' => null];
        }

        $value = $item['value'] ?? null;

        if (is_array($value) === true && isset($value['__chunks']) === true) {
            $chunksList = $value['__chunks'];
            if (is_array($chunksList) === false) {
                $this->logger->warning('Corrupted chunk list in memcached, invalidating entry');
                $this->delete($key);
                return ['fresh' => null, 'stale' => null];
            }

            $chunkKeys = [];
            foreach ($chunksList as $chunkKey) {
                if (is_string($chunkKey) === false) {
                    $this->logger->warning('Corrupted chunk list in memcached (non-string key), invalidating entry');
                    $this->delete($key);
                    return ['fresh' => null, 'stale' => null];
                }
                $chunkKeys[] = $chunkKey;
            }

            $payload = $this->readChunked($chunkKeys);
            if ($payload === null) {
                $this->logger->info(sprintf('Discarding incomplete chunked entry for key "%s"', $key));
                $this->delete($key);
                return ['fresh' => null, 'stale' => null];
            }

            $decoded = $this->decode($payload, $key);
            if ($decoded === null) {
                $this->delete($key);
                return ['fresh' => null, 'stale' => null];
            }
            return $decoded;
        }

        if (is_string($value) === false) {
            $this->logger->info(sprintf('Discarding invalid (non-string) cache entry for key "%s"', $key));
            $this->delete($key);
            return ['fresh' => null, 'stale' => null];
        }

        $decoded = $this->decode($value, $key);
        if ($decoded === null) {
            $this->logger->info(sprintf('Discarding legacy or corrupt cache entry for key "%s"', $key));
            $this->delete($key);
            return ['fresh' => null, 'stale' => null];
        }
        return $decoded;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $cacheKey = $this->createCacheKey($key);
        $now = time();
        $freshUntil = $ttl === null ? 0 : $now + $ttl;

        $serverExpiresAt = match (true) {
            $ttl === null => 0,
            $freshUntil <= $now => 1,
            default => $freshUntil + self::DEFAULT_STALE_TTL,
        };

        try {
            $encoded = $this->encode($value, $freshUntil);
        } catch (\JsonException | \InvalidArgumentException $e) {
            $this->logger->error(sprintf('Failed to encode cache key "%s": %s', $key, $e->getMessage()));
            return;
        }

        $previousChunkKeys = $this->readChunkKeys($cacheKey);

        $storeValue = $encoded;
        $chunkKeys = [];

        if (strlen($encoded) > $this->chunkSize) {
            $parts = str_split($encoded, $this->chunkSize);

            if (count($parts) > $this->maxChunks) {
                $this->logger->warning('Refusing to store oversized memcached item', [
                    'key'       => $cacheKey,
                    'size'      => strlen($encoded),
                    'maxChunks' => $this->maxChunks,
                ]);
                return;
            }

            $generation = bin2hex(random_bytes(8));
            $chunkFailed = false;
            $this->conn->setOption(\Memcached::OPT_COMPRESSION, false);
            try {
                foreach ($parts as $index => $part) {
                    $chunkKey = $cacheKey . '#' . $generation . sprintf('c%04d', $index);
                    $chunkKeys[] = $chunkKey;

                    if ($this->conn->set($chunkKey, $part, $serverExpiresAt) === false) {
                        $this->logStoreFailure($chunkKey, strlen($part));
                        $chunkFailed = true;
                        break;
                    }
                }
            } finally {
                $this->conn->setOption(\Memcached::OPT_COMPRESSION, true);
            }

            if ($chunkFailed === true) {
                foreach ($chunkKeys as $chunkKey) {
                    $this->conn->delete($chunkKey);
                }
                return;
            }

            $storeValue = ['__chunks' => $chunkKeys];
        }

        $item = [
            'value' => $storeValue,
        ];

        if ($this->conn->set($cacheKey, $item, $serverExpiresAt) === false) {
            if ($chunkKeys !== []) {
                foreach ($chunkKeys as $chunkKey) {
                    $this->conn->delete($chunkKey);
                }
            }
            $itemJson = json_encode($item);
            $this->logStoreFailure($cacheKey, $itemJson === false ? 0 : strlen($itemJson));
            return;
        }

        foreach ($previousChunkKeys as $chunkKey) {
            $this->conn->delete($chunkKey);
        }
    }

    public function delete(string $key): void
    {
        $cacheKey = $this->createCacheKey($key);

        foreach ($this->readChunkKeys($cacheKey) as $chunkKey) {
            $this->conn->delete($chunkKey);
        }

        $this->conn->delete($cacheKey);
    }

    /**
     * @return list<string>
     */
    private function readChunkKeys(string $cacheKey): array
    {
        $item = $this->conn->get($cacheKey);
        $value = is_array($item) === true ? ($item['value'] ?? null) : null;
        if (
            is_array($value) === false
            || array_key_exists('__chunks', $value) === false
            || is_array($value['__chunks']) === false
        ) {
            return [];
        }

        $keys = [];
        foreach ($value['__chunks'] as $chunkKey) {
            if (is_string($chunkKey) === true) {
                $keys[] = $chunkKey;
            }
        }
        return $keys;
    }

    /**
     * Fetch all chunk keys via a single multi-get and concatenate payloads.
     *
     * @param list<string> $chunkKeys
     * @return string|null Reassembled JSON payload or null if any chunk is missing
     */
    private function readChunked(array $chunkKeys): ?string
    {
        // No flags: GET_EXTENDED (2) would wrap values in arrays and break the
        // is_string() checks below; the previous "1024" was not a valid flag.
        $values = $this->conn->getMulti($chunkKeys);
        $resultCode = $this->conn->getResultCode();

        // Partial hits come back with RES_NOTFOUND while fully successful
        // multi-gets return RES_SUCCESS; anything else is a server error.
        if ($resultCode !== self::RES_SUCCESS && $resultCode !== self::RES_NOTFOUND) {
            $this->logger->warning(sprintf(
                'Memcached chunked get failed (code %d: %s)',
                $resultCode,
                $this->conn->getResultMessage()
            ));
            return null;
        }

        if (is_array($values) === false || count($values) !== count($chunkKeys)) {
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

        $context = [
            'key'           => $cacheKey,
            'size'          => $size,
            'resultCode'    => $resultCode,
            'resultMessage' => $resultMessage,
        ];

        if (
            $resultCode === self::RES_SERVER_ERROR
            || $resultCode === self::RES_CONNECTION_FAILURE
            || $resultCode === self::RES_HOST_LOOKUP_FAILURE
            || $resultCode === self::RES_TIMEOUT
        ) {
            $this->logger->error('Failed to store an item in memcached', $context);
        } elseif ($resultCode === self::RES_NOTSTORED) {
            $this->logger->debug('Failed to store an item in memcached', $context);
        } elseif ($resultCode === self::RES_E2BIG) {
            $this->logger->warning('Failed to store an item in memcached (item too large)', $context);
        } else {
            $this->logger->warning('Failed to store an item in memcached', $context);
        }
    }

    public function clear(): void
    {
        if ($this->allowFlush === true) {
            $this->conn->flush();
            return;
        }

        $keys = $this->conn->getAllKeys();
        if (is_array($keys) === false) {
            $this->logger->warning('Memcached clear() skipped: getAllKeys() unavailable on external server');
            return;
        }

        foreach ($keys as $key) {
            if (is_string($key) === true && str_starts_with($key, $this->cachePrefix) === true) {
                $this->conn->delete($key);
            }
        }
    }

    public function prune(): void
    {
        // Memcached manages expiration automatically
    }

    /**
     * Distributed single-flight lock using memcached's CAS semantics:
     * add() only stores when the key is absent, so exactly one process wins.
     */
    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        $lockKey = $this->createCacheKey('lock_' . $key);
        $token = bin2hex(random_bytes(16));

        try {
            $stored = $this->conn->add($lockKey, $token, $ttlSeconds);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                'Memcached lock acquire failed (%s): proceeding without single-flight',
                $e->getMessage()
            ));
            return true;
        }

        if ($stored === false) {
            $code = $this->conn->getResultCode();
            if ($code === self::RES_NOTSTORED || $code === self::RES_DATA_EXISTS) {
                return false;
            }
            $this->logger->warning(sprintf('Memcached add() for lock failed (code %d)', $code));
            return true;
        }

        $this->lockTokens[$key] = $token;
        return true;
    }

    public function releaseLock(string $key): void
    {
        $token = $this->lockTokens[$key] ?? null;
        if (is_string($token) === false) {
            return;
        }
        unset($this->lockTokens[$key]);

        $lockKey = $this->createCacheKey('lock_' . $key);

        try {
            $result = $this->conn->get($lockKey, null, \Memcached::GET_EXTENDED);
            if (is_array($result) === false) {
                return;
            }

            $stored = $result['value'] ?? null;
            if (is_string($stored) === false || $stored !== $token) {
                return;
            }

            $casRaw = $result['cas'] ?? null;
            $cas = null;
            if (is_float($casRaw) === true) {
                $cas = $casRaw;
            } elseif (is_int($casRaw) === true) {
                $cas = (float) $casRaw;
            } elseif (is_string($casRaw) === true && is_numeric($casRaw) === true) {
                $cas = (float) $casRaw;
            }

            if ($cas === null) {
                return;
            }

            $this->conn->cas($cas, $lockKey, '', 1);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('Memcached lock release failed (%s)', $e->getMessage()));
        }
    }

    /**
     * Get statistics about the memcached server.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getStats(): array
    {
        $stats = $this->conn->getStats();
        if (is_array($stats) === false) {
            return [];
        }

        $result = [];
        foreach ($stats as $server => $serverStats) {
            if (is_string($server) === false || is_array($serverStats) === false) {
                continue;
            }

            $normalized = [];
            foreach ($serverStats as $key => $value) {
                if (is_string($key) === true) {
                    $normalized[$key] = $value;
                }
            }

            $result[$server] = $normalized;
        }

        return $result;
    }

    protected function createCacheKey(string $key): string
    {
        return $this->cachePrefix . hash('sha256', $key);
    }
}
