<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Logger\Logger;
use RSSBridge\Caches\CacheInterface;

/**
 * Safe placeholder returned by {@see SafeBridgeLoader} when a bridge
 * cannot be loaded (e.g. because of a syntax error, a compile-time
 * signature mismatch, or any other fatal failure).
 *
 * This class extends {@see BridgeAbstract} so that the rest of the
 * application can treat it like any other bridge. All metadata getters
 * return safe defaults, and {@see collectData()} throws an exception
 * with the original error message so that {@see \DisplayAction} can
 * surface it to the user. Broken stubs are short-circuited before any
 * cache write, so their timeout value (0) is never persisted.
 */
class BrokenBridgeStub extends BridgeAbstract
{
    private string $originalName;
    private string $errorMessage;

    /**
     * @param string $originalName Short name of the bridge that failed to load
     * @param string $errorMessage Error message describing the failure
     * @param CacheInterface|null $cache Optional cache instance passed to the parent constructor
     * @param Logger|null $logger Optional logger instance passed to the parent constructor
     */
    public function __construct(string $originalName, string $errorMessage, ?CacheInterface $cache = null, ?Logger $logger = null)
    {
        parent::__construct($cache, $logger);
        $this->originalName = $originalName;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Marks this instance as a broken stub so that callers can detect it
     * without relying on instanceof checks.
     */
    public function isBrokenStub(): bool
    {
        return true;
    }

    /**
     * Always throws an exception: broken stubs cannot collect data.
     *
     * @throws \Exception
     */
    public function collectData(): void
    {
        throw new \Exception('This bridge is broken: ' . $this->errorMessage);
    }

    /**
     * Returns the original bridge name suffixed with " (Broken)" so that
     * the frontend clearly indicates which bridges are disabled.
     */
    public function getName(): string
    {
        return $this->originalName . ' (Broken)';
    }

    public function getURI(): string
    {
        return '';
    }

    public function getIcon(): string
    {
        return '';
    }

    /**
     * Returns an empty parameter list, since a a broken bridge cannot
     * be configured by the user.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function getParameters(): array
    {
        return [];
    }

    /**
     * Returns a description containing the original error message,
     * useful for administrators and debugging.
     */
    public function getDescription(): string
    {
        return 'This bridge is broken: ' . $this->errorMessage;
    }

    public function getMaintainer(): string
    {
        return 'System';
    }

    /**
     * Returns a cache timeout of 0 so that broken stubs are never cached
     * and can be automatically retried after a deployment fix.
     *
     * Note: DisplayAction short-circuits broken stubs before reaching the
     * cache write, and CacheMiddleware skips persisting responses whose
     * '_cache_timeout' request attribute is 0 (set by ExceptionMiddleware).
     */
    public function getCacheTimeout(): int
    {
        return 0;
    }
}
