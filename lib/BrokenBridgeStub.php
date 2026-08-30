<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Caches\CacheInterface;

/**
 * Safe placeholder returned by {@see SafeBridgeLoader} when a bridge
 * cannot be loaded (e.g. because of a syntax error, a compile-time
 * signature mismatch, or any other fatal failure).
 *
 * This class intentionally extends {@see BridgeAbstract} so that the
 * rest of the application can treat it like any other bridge. All
 * metadata getters return safe defaults, and {@see collectData()}
 * throws an exception with the original error message so that
 * {@see \DisplayAction} can surface it to the user.
 *
 * IMPORTANT: Method signatures deliberately omit type hints to avoid
 * any conflict with the untyped signatures of {@see BridgeAbstract},
 * which is required for compatibility with legacy bridges.
 */
class BrokenBridgeStub extends BridgeAbstract
{
    private string $originalName;
    private string $errorMessage;

    /**
     * @param string $originalName Short name of the bridge that failed to load
     * @param string $errorMessage Error message describing the failure
     * @param CacheInterface|null $cache Optional cache instance passed to the parent constructor
     * @param \Logger|null $logger Optional logger instance passed to the parent constructor
     */
    public function __construct(string $originalName, string $errorMessage, ?CacheInterface $cache = null, ?\Logger $logger = null)
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
     * Returns an empty parameter list, since a broken bridge cannot
     * be configured by the user.
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
     * Always returns null, since a broken bridge cannot detect parameters
     * from a URL.
     *
     * @param mixed $url
     */
    public function detectParameters($url): mixed
    {
        return null;
    }

    /**
     * Returns a cache timeout of 0 so that broken stubs are never cached
     * for long and can be automatically retried after a deployment fix.
     */
    public function getCacheTimeout(): int
    {
        return 0;
    }
}
