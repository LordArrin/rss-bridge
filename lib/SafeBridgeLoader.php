<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Caches\CacheInterface;

/**
 * Safe bridge loader that wraps BridgeFactory to prevent application crashes.
 *
 * When a bridge cannot be loaded (due to syntax errors, signature mismatches,
 * or any other fatal failure), this loader returns a {@see BrokenBridgeStub}
 * instead of propagating the exception, allowing the rest of the application
 * to continue functioning.
 */
class SafeBridgeLoader
{
    private BridgeFactory $bridgeFactory;
    private \Logger $logger;
    private CacheInterface $cache;
    private array $brokenBridges = [];

    public function __construct(BridgeFactory $bridgeFactory, \Logger $logger, CacheInterface $cache)
    {
        $this->bridgeFactory = $bridgeFactory;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Safely creates a bridge instance.
     *
     * If the bridge cannot be instantiated for any reason, returns a
     * {@see BrokenBridgeStub} and records the error for later reporting.
     *
     * @param string $bridgeClassName Full class name (FQCN or legacy global name)
     * @return BridgeAbstract Either the real bridge or a broken stub
     */
    public function createSafely(string $bridgeClassName): BridgeAbstract
    {
        if (empty($bridgeClassName) === true || preg_match('/^[a-zA-Z0-9_\\\\]+$/', $bridgeClassName) !== 1) {
            $stubName = (empty($bridgeClassName) === false) ? $bridgeClassName : 'Unknown';
            return $this->createStub($stubName, 'Invalid bridge class name');
        }

        try {
            $bridge = $this->bridgeFactory->create($bridgeClassName);

            $bridge->getName();
            $bridge->getURI();
            $bridge->getDescription();
            $bridge->getParameters();

            return $bridge;
        } catch (\Throwable $e) {
            $this->registerBrokenBridge($bridgeClassName, $e);
            return $this->createStub($bridgeClassName, $e->getMessage(), $e->getFile(), $e->getLine());
        }
    }

    /**
     * Checks whether a bridge instance is a broken stub.
     *
     * @param BridgeAbstract $bridge Bridge instance to check
     */
    public function isBridgeBroken(BridgeAbstract $bridge): bool
    {
        return $bridge instanceof BrokenBridgeStub || (method_exists($bridge, 'isBrokenStub') && $bridge->isBrokenStub());
    }

    /**
     * Returns the list of all broken bridges encountered during loading.
     *
     * @return array<string, array{message: string, file: string, line: int, class: string}>
     */
    public function getBrokenBridges(): array
    {
        return $this->brokenBridges;
    }

    /**
     * Restores a broken bridge entry from cache.
     *
     * This is used by {@see BridgeMetadataCache} to restore the broken bridges
     * list when metadata is served from cache, so that the frontend can display
     * warnings even without re-loading all bridges.
     *
     * @param string $bridgeName Bridge class name
     * @param array $errorInfo Error information array
     */
    public function restoreBrokenBridge(string $bridgeName, array $errorInfo): void
    {
        $this->brokenBridges[$bridgeName] = $errorInfo;
    }

    /**
     * Clears the list of broken bridges.
     */
    public function resetBrokenBridges(): void
    {
        $this->brokenBridges = [];
    }

    /**
     * Creates a safe stub for a broken bridge.
     *
     * @param string $originalName Original bridge class name
     * @param string $errorMessage Error message describing the failure
     * @param string $file File where the error occurred
     * @param int $line Line number where the error occurred
     */
    private function createStub(string $originalName, string $errorMessage, string $file = '', int $line = 0): BrokenBridgeStub
    {
        return new BrokenBridgeStub($originalName, $errorMessage, $this->cache, $this->logger);
    }

    /**
     * Records a broken bridge and logs the error.
     *
     * @param string $bridgeClassName Bridge class name that failed to load
     * @param \Throwable $e Exception or error that caused the failure
     */
    private function registerBrokenBridge(string $bridgeClassName, \Throwable $e): void
    {
        $this->brokenBridges[$bridgeClassName] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'class' => get_class($e),
        ];

        $this->logger->error(sprintf(
            '[SafeBridgeLoader] Bridge "%s" failed to load: [%s] %s in %s:%d',
            $bridgeClassName,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
