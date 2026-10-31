<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * In-memory/runtime cache.
 * Data is lost when the process ends.
 * Useful for testing or single-request caching.
 *
 * Supports Stale-while-revalidate: expired entries remain accessible
 * via getWithStale() until explicitly pruned or the process terminates.
 */
final class ArrayCache extends AbstractCache
{
    /**
     * @var array<string, string> Raw JSON blobs (encoded via AbstractCache)
     */
    private array $data = [];

    /**
     * @var array<string, int> key => lease expiry timestamp
     */
    private array $locks = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->getWithStale($key);

        return $cached['fresh'] ?? $default;
    }

    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        $now = time();
        $expiry = $this->locks[$key] ?? 0;

        if ($expiry > $now) {
            return false;
        }

        $this->locks[$key] = $now + $ttlSeconds;
        return true;
    }

    public function getWithStale(string $key): array
    {
        if (array_key_exists($key, $this->data) === false) {
            return ['fresh' => null, 'stale' => null];
        }

        $blob = $this->data[$key];

        // Use AbstractCache::decode() for JSON deserialization
        $decoded = $this->decode($blob, $key);

        return $decoded;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $now = time();
        $freshUntil = $ttl === null ? 0 : $now + $ttl;

        try {
            // Use AbstractCache::encode() for JSON serialization
            $this->data[$key] = $this->encode($value, $freshUntil);
        } catch (\JsonException $e) {
            // For in-memory cache, just skip storing if encoding fails
            return;
        }
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
        $this->locks = [];
    }

    public function prune(): void
    {
        $now = time();

        foreach ($this->data as $key => $blob) {
            try {
                $decoded = json_decode($blob, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                unset($this->data[$key]);
                continue;
            }

            if (is_array($decoded) === false || array_key_exists('fresh_until', $decoded) === false) {
                unset($this->data[$key]);
                continue;
            }

            $freshUntil = (int) ($decoded['fresh_until'] ?? 0);
            $hardExpiration = $freshUntil + self::DEFAULT_STALE_TTL;

            if ($hardExpiration !== 0 && $hardExpiration <= $now) {
                unset($this->data[$key]);
            }
        }
    }
}
