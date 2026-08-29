<?php

declare(strict_types=1);

/**
 * Safe placeholder returned by {@see SafeBridgeLoader} when a bridge
 * cannot be loaded (e.g. because of a syntax error, a compile-time
 * signature mismatch, or any other fatal failure).
 *
 * This class intentionally extends {@see BridgeAbstract} so that the
 * rest of the application can treat it like any other bridge. All
 * metadata getters return safe defaults, and {@see collectData()}
 * throws an exception with the original error message so that
 * {@see DisplayAction} can surface it to the user.
 *
 * IMPORTANT: Method signatures deliberately omit type hints to avoid
 * any conflict with the untyped signatures of {@see BridgeAbstract},
 * which is required for compatibility with legacy bridges.
 */
class BrokenBridgeStub extends BridgeAbstract
{
    /**
     * @var string
     */
    private $originalName;

    /**
     * @var string
     */
    private $errorMessage;

    /**
     * @param string $originalName Short name of the bridge that failed to load
     * @param string $errorMessage Error message describing the failure
     * @param mixed $cache Optional cache instance passed to the parent constructor
     * @param mixed $logger Optional logger instance passed to the parent constructor
     */
    public function __construct($originalName, $errorMessage, $cache = null, $logger = null)
    {
        parent::__construct($cache, $logger);
        $this->originalName = $originalName;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Marks this instance as a broken stub so that callers can detect it
     * without relying on instanceof checks.
     */
    public function isBrokenStub()
    {
        return true;
    }

    /**
     * Always throws an exception: broken stubs cannot collect data.
     *
     * @throws \Exception
     */
    public function collectData()
    {
        throw new \Exception('This bridge is broken: ' . $this->errorMessage);
    }

    /**
     * Returns the original bridge name suffixed with " (Broken)" so that
     * the frontend clearly indicates which bridges are disabled.
     */
    public function getName()
    {
        return $this->originalName . ' (Broken)';
    }

    public function getURI()
    {
        return '';
    }

    public function getIcon()
    {
        return '';
    }

    /**
     * Returns an empty parameter list, since a broken bridge cannot
     * be configured by the user.
     */
    public function getParameters()
    {
        return [];
    }

    /**
     * Returns a description containing the original error message,
     * useful for administrators and debugging.
     */
    public function getDescription()
    {
        return 'This bridge is broken: ' . $this->errorMessage;
    }

    public function getMaintainer()
    {
        return 'System';
    }

    /**
     * Always returns null, since a broken bridge cannot detect parameters
     * from a URL.
     *
     * @param string $url
     * @return null
     */
    public function detectParameters($url)
    {
        return null;
    }

    /**
     * Returns a cache timeout of 0 so that broken stubs are never cached
     * for long and can be automatically retried after a deployment fix.
     */
    public function getCacheTimeout()
    {
        return 0;
    }
}
