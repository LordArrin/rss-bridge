<?php

/**
 * SafeBridgeLoader - ensures safe loading of bridges without crashing the application.
 *
 * This class wraps BridgeFactory and intercepts errors when creating bridges,
 * returning safe stubs instead of crashing the script.
 */
class SafeBridgeLoader
{
    private BridgeFactory $bridgeFactory;
    private Logger $logger;
    private array $brokenBridges = [];

    public function __construct(BridgeFactory $bridgeFactory, Logger $logger)
    {
        $this->bridgeFactory = $bridgeFactory;
        $this->logger = $logger;
    }

    /**
     * Safely creates a bridge instance
     * In case of an error, returns a stub and remembers the error
     */
    public function createSafely(string $bridgeClassName): BridgeAbstract
    {
        try {
            $bridge = $this->bridgeFactory->create($bridgeClassName);
            
            // Validating basic metadata
            $bridge->getName();
            $bridge->getURI();
            $bridge->getDescription();
            $bridge->getParameters();
            
            return $bridge;
            
        } catch (\Throwable $e) {
            $this->brokenBridges[$bridgeClassName] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
            
            $this->logger->error(sprintf(
                'Bridge "%s" is invalid: %s in %s:%d',
                $bridgeClassName,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            
            return $this->createBrokenBridgeStub($bridgeClassName, $e->getMessage());
        }
    }

    /**
     * Checks if the bridge is broken
     */
    public function isBridgeBroken(BridgeAbstract $bridge): bool
    {
        return method_exists($bridge, 'isBrokenStub') && $bridge->isBrokenStub();
    }

    /**
     * Returns a list of all broken bridges.
     */
    public function getBrokenBridges(): array
    {
        return $this->brokenBridges;
    }

    /**
     * Clears the list of broken bridges
     */
    public function resetBrokenBridges(): void
    {
        $this->brokenBridges = [];
    }

    /**
     * Creates a safe plug for a broken bridge
     */
    private function createBrokenBridgeStub(string $originalName, string $errorMessage): BridgeAbstract
    {
        return new class($originalName, $errorMessage) extends BridgeAbstract {
            private string $error;
            private string $originalName;

            public function __construct(string $originalName, string $error)
            {
                // We don't call parent::__construct because we don't have a cache/logger.
                $this->originalName = $originalName;
                $this->error = $error;
            }

            public function isBrokenStub(): bool 
            { 
                return true; 
            }

            public function collectData() 
            { 
                throw new \Exception('This bridge is broken: ' . $this->error); 
            }

            public function getName() 
            { 
                return $this->originalName . ' (Broken)'; 
            }

            public function getURI() 
            { 
                return ''; 
            }

            public function getDonationURI(): string 
            { 
                return ''; 
            }

            public function getIcon() 
            { 
                return ''; 
            }

            public function getParameters(): array 
            { 
                return []; 
            }

            public function getDescription() 
            { 
                return 'This bridge is broken: ' . $this->error; 
            }

            public function getMaintainer(): string 
            { 
                return 'System'; 
            }
            
            public function detectParameters($url) 
            { 
                return null; 
            }
            
            public function getCacheTimeout() 
            { 
                return 0; 
            }
        };
    }
}