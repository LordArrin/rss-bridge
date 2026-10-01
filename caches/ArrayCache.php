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
final class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, array{value: mixed, fresh_until: int, expires_at: int}>
     */
    private array $data = [];

    private const DEFAULT_STALE_TTL = 604800;

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->getWithStale($key);

        return $cached['fresh'] ?? $default;
    }

    public function getWithStale(string $key): array
    {
        if (array_key_exists($key, $this->data) === false) {
            return ['fresh' => null, 'stale' => null];
        }

        $item = $this->data[$key];
        $now = time();

        if ($item['expires_at'] !== 0 && $item['expires_at'] <= $now) {
            unset($this->data[$key]);
            return ['fresh' => null, 'stale' => null];
        }

        $isFresh = ($item['fresh_until'] === 0 || $item['fresh_until'] > $now);

        return [
            'fresh' => $isFresh === true ? $item['value'] : null,
            'stale' => $item['value'],
        ];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $now = time();
        $freshUntil = $ttl === null ? 0 : $now + $ttl;
        $expiresAt = $ttl === null ? 0 : $freshUntil + self::DEFAULT_STALE_TTL;

        $this->data[$key] = [
            'value'       => $value,
            'fresh_until' => $freshUntil,
            'expires_at'  => $expiresAt,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function prune(): void
    {
        $now = time();

        foreach ($this->data as $key => $item) {
            if ($item['expires_at'] !== 0 && $item['expires_at'] <= $now) {
                unset($this->data[$key]);
            }
        }
    }
}
