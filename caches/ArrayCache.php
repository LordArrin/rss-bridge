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

    /**
     * @var array<string, int> key => generation recorded at acquire time
     */
    private array $lockGenerations = [];

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

        $generation = $now + $ttlSeconds;
        $this->locks[$key] = $generation;
        $this->lockGenerations[$key] = $generation;
        return true;
    }

    public function releaseLock(string $key): void
    {
        $generation = $this->lockGenerations[$key] ?? null;
        if (is_int($generation) === false) {
            return;
        }
        unset($this->lockGenerations[$key]);

        if (($this->locks[$key] ?? null) === $generation) {
            unset($this->locks[$key]);
        }
    }

    public function prune(): void
    {
        $now = time();

        foreach ($this->locks as $lockKey => $expiry) {
            if ($expiry <= $now) {
                unset($this->locks[$lockKey]);
            }
        }

        foreach ($this->lockGenerations as $lockKey => $generation) {
            if ($generation <= $now) {
                unset($this->lockGenerations[$lockKey]);
            }
        }

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

            $freshUntilRaw = $decoded['fresh_until'];
            $freshUntil = 0;
            if (is_int($freshUntilRaw) === true) {
                $freshUntil = $freshUntilRaw;
            } elseif (is_string($freshUntilRaw) === true && is_numeric($freshUntilRaw) === true) {
                $freshUntil = (int) $freshUntilRaw;
            }

            if ($freshUntil <= 0) {
                continue;
            }

            $hardExpiration = $freshUntil + self::DEFAULT_STALE_TTL;
            if ($hardExpiration <= $now) {
                unset($this->data[$key]);
            }
        }
    }

    public function getWithStale(string $key): array
    {
        if (array_key_exists($key, $this->data) === false) {
            return ['fresh' => null, 'stale' => null];
        }

        $blob = $this->data[$key];

        $decoded = $this->decode($blob, $key);
        if ($decoded === null) {
            unset($this->data[$key]);
            return ['fresh' => null, 'stale' => null];
        }

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
            $this->data[$key] = $this->encode($value, $freshUntil);
        } catch (\JsonException | \InvalidArgumentException $e) {
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
        $this->lockGenerations = [];
    }
}
