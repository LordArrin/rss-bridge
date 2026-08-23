<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * In-memory/runtime cache.
 * Data is lost when the process ends.
 * Useful for testing or single-request caching.
 */
final class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, array{value: mixed, expiration: int}>
     */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->data) === false) {
            return $default;
        }

        $item = $this->data[$key];
        $expiration = $item['expiration'];

        if ($expiration === 0 || $expiration > time()) {
            return $item['value'];
        }

        unset($this->data[$key]);
        return $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $this->data[$key] = [
            'value'      => $value,
            'expiration' => $ttl === null ? 0 : time() + $ttl,
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
            $expiration = $item['expiration'];
            if ($expiration !== 0 && $expiration <= $now) {
                unset($this->data[$key]);
            }
        }
    }
}
