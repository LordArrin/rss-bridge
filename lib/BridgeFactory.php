<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;

/**
 * Factory for creating and managing bridge instances.
 *
 * This class is responsible for scanning bridge directories, resolving bridge
 * class names (supporting both legacy global namespace and PSR-4 namespaced
 * bridges), and safely instantiating bridge objects.
 *
 * It uses an isolated "sandbox" process to detect fatal compile errors
 * (like signature mismatches in legacy bridges) before loading them into
 * the main process, preventing the entire application from crashing.
 */
final class BridgeFactory
{
    private CacheInterface $cache;
    private Logger $logger;

    /**
     * Array of all available bridge class names (FQCN or legacy global name).
     * For legacy bridges: 'TelegramBridge'
     * For PSR-4 bridges: 'RSSBridge\Bridges\TelegramBridge'
     *
     * @var string[]
     */
    private array $bridgeClassNames = [];

    /**
     * Map from lowercase short name to FQCN for fast lookup.
     * Example: ['telegrambridge' => 'RSSBridge\Bridges\TelegramBridge']
     *
     * @var array<string, string>
     */
    private array $shortNameMap = [];

    /**
     * List of bridge class names that are enabled in the configuration.
     *
     * @var string[]
     */
    private array $enabledBridges = [];

    /**
     * List of enabled bridges from config that were not found on disk.
     *
     * @var string[]
     */
    private array $missingEnabledBridges = [];

    public function __construct(CacheInterface $cache, Logger $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;

        $this->scanBridges();
        $this->loadEnabledBridges();
    }

    /**
     * Scans bridge directories and populates bridgeClassNames and shortNameMap.
     *
     * PSR-4 bridges (in bridges-v2/) take precedence over legacy bridges
     * (in bridges/) when both have the same short name.
     */
    private function scanBridges(): void
    {
        $this->bridgeClassNames = [];
        $this->shortNameMap = [];

        $legacyDir = __DIR__ . '/../bridges/';
        if (is_dir($legacyDir)) {
            foreach (scandir($legacyDir) as $file) {
                if (preg_match('/^([^.]+Bridge)\.php$/U', $file, $m)) {
                    $shortName = $m[1];
                    $this->bridgeClassNames[] = $shortName;
                    $this->shortNameMap[strtolower($shortName)] = $shortName;
                }
            }
        }

        $v2Dir = __DIR__ . '/../bridges-v2/';
        if (is_dir($v2Dir)) {
            foreach (scandir($v2Dir) as $file) {
                if (preg_match('/^([^.]+Bridge)\.php$/U', $file, $m)) {
                    $shortName = $m[1];
                    $fqcn = 'RSSBridge\\Bridges\\' . $shortName;

                    $lowerShortName = strtolower($shortName);
                    if (isset($this->shortNameMap[$lowerShortName])) {
                        $legacyFqcn = $this->shortNameMap[$lowerShortName];
                        $index = array_search($legacyFqcn, $this->bridgeClassNames, true);
                        if ($index !== false) {
                            unset($this->bridgeClassNames[$index]);
                            $this->bridgeClassNames = array_values($this->bridgeClassNames);
                        }
                        $this->logger->info(sprintf(
                            'PSR-4 bridge "%s" overrides legacy bridge with the same name',
                            $shortName
                        ));
                    }

                    $this->bridgeClassNames[] = $fqcn;
                    $this->shortNameMap[$lowerShortName] = $fqcn;
                }
            }
        }

        sort($this->bridgeClassNames);
    }

    /**
     * Loads the list of enabled bridges from configuration.
     *
     * The special value '*' enables all discovered bridges.
     * Bridges that are configured but not found on disk are logged
     * and stored in {@see getMissingEnabledBridges()}.
     *
     * @throws \Exception If no bridges are enabled at all
     */
    private function loadEnabledBridges(): void
    {
        $enabledBridges = Configuration::getConfig('system', 'enabled_bridges');
        if ($enabledBridges === null) {
            throw new \Exception('No bridges are enabled...');
        }

        foreach ($enabledBridges as $enabledBridge) {
            if ($enabledBridge === '*') {
                $this->enabledBridges = $this->bridgeClassNames;
                break;
            }

            $bridgeClassName = $this->createBridgeClassName($enabledBridge);
            if ($bridgeClassName) {
                $this->enabledBridges[] = $bridgeClassName;
            } else {
                $this->missingEnabledBridges[] = $enabledBridge;
                $this->logger->info(sprintf('Bridge not found: %s', $enabledBridge));
            }
        }
    }

    /**
     * Creates a bridge instance by class name or short name.
     *
     * The bridge file is loaded inside an isolated subprocess (sandbox) first
     * to catch fatal compile errors like signature mismatches. Only if the
     * sandbox check passes, the file is included in the main process.
     *
     * @param string $name Bridge class name (FQCN) or short name
     * @return BridgeAbstract
     * @throws \Exception If the bridge cannot be found, loaded, or instantiated
     */
    public function create(string $name): BridgeAbstract
    {
        $resolved = $this->createBridgeClassName($name);
        if ($resolved === null) {
            throw new \Exception(sprintf('Bridge class not found: %s', $name));
        }

        if (!class_exists($resolved, false)) {
            $file = $this->getBridgeFilePath($resolved);
            if ($file && is_readable($file)) {
                $this->loadBridgeInSandbox($file);
                include_once $file;
            }
        }

        if (!class_exists($resolved, false)) {
            throw new \Exception(sprintf('Bridge class does not exist after include: %s', $resolved));
        }

        try {
            $reflection = new \ReflectionClass($resolved);
            $constructor = $reflection->getConstructor();

            if ($constructor !== null && count($constructor->getParameters()) >= 2) {
                return new $resolved($this->cache, $this->logger);
            }

            return new $resolved();
        } catch (\Throwable $e) {
            throw new \Exception(sprintf('Cannot instantiate bridge %s: %s', $resolved, $e->getMessage()));
        }
    }

    /**
     * Loads a bridge file inside an isolated PHP subprocess to detect
     * fatal compile errors (e.g. signature mismatches with parent classes).
     *
     * This prevents the main process from crashing when a legacy bridge
     * has incompatible type declarations with its parent abstract class.
     *
     * @param string $file Absolute path to the bridge PHP file
     * @throws \Exception If the sandbox check fails
     */
    private function loadBridgeInSandbox(string $file): void
    {
        $testScript = tempnam(sys_get_temp_dir(), 'rssbridge_sandbox_') . '.php';
        $bootstrapPath = realpath(__DIR__ . '/bootstrap.php');
        $vendorPath = realpath(__DIR__ . '/../vendor/autoload.php');

        $code = "<?php\n";
        if ($vendorPath) {
            $code .= "require '" . addslashes($vendorPath) . "';\n";
        }
        if ($bootstrapPath) {
            $code .= "require '" . addslashes($bootstrapPath) . "';\n";
        }
        $code .= "try {\n";
        $code .= "    require '" . addslashes($file) . "';\n";
        $code .= "    echo 'SANDBOX_SUCCESS';\n";
        $code .= "} catch (\\Throwable \$e) {\n";
        $code .= "    echo 'SANDBOX_EXCEPTION: ' . \$e->getMessage() . \"\\n\";\n";
        $code .= "}\n";

        file_put_contents($testScript, $code);

        $output = [];
        $returnVar = 0;
        exec('php ' . escapeshellarg($testScript) . ' 2>&1', $output, $returnVar);
        unlink($testScript);

        $result = trim(implode("\n", $output));

        if ($returnVar !== 0 || $result !== 'SANDBOX_SUCCESS') {
            throw new \Exception('Bridge compatibility error: ' . $result);
        }
    }

    /**
     * Resolves the filesystem path to a bridge file by its class name.
     *
     * Checks the PSR-4 directory (bridges-v2/) first, then the legacy
     * directory (bridges/).
     *
     * @param string $className Full class name (FQCN or legacy global name)
     * @return string|null Absolute path to the file, or null if not found
     */
    private function getBridgeFilePath(string $className): ?string
    {
        $shortName = $this->getShortClassName($className);

        $v2File = __DIR__ . '/../bridges-v2/' . $shortName . '.php';
        if (file_exists($v2File)) {
            return $v2File;
        }

        $legacyFile = __DIR__ . '/../bridges/' . $shortName . '.php';
        if (file_exists($legacyFile)) {
            return $legacyFile;
        }

        return null;
    }

    /**
     * Checks whether a bridge is enabled in the configuration.
     *
     * @param string $bridgeName Full class name (FQCN or legacy global name)
     */
    public function isEnabled(string $bridgeName): bool
    {
        return in_array($bridgeName, $this->enabledBridges, true);
    }

    /**
     * Resolves a bridge class name from a short name, legacy name, or FQCN.
     *
     * @param string $bridgeName Any accepted bridge name format
     * @return string|null The full class name, or null if not found
     */
    public function createBridgeClassName(string $bridgeName): ?string
    {
        $name = self::normalizeBridgeName($bridgeName);
        $nameLower = strtolower($name);

        if (isset($this->shortNameMap[$nameLower])) {
            return $this->shortNameMap[$nameLower];
        }

        if (class_exists($bridgeName, false)) {
            return $bridgeName;
        }

        return null;
    }

    /**
     * Normalizes a bridge name to its canonical short form (e.g. 'TelegramBridge').
     *
     * Strips any namespace prefix and the `.php` extension, and appends
     * the 'Bridge' suffix if it is missing.
     *
     * @param string $name Raw bridge name from input
     * @return string Canonical short name
     */
    public static function normalizeBridgeName(string $name): string
    {
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $name = end($parts);
        }

        if (preg_match('/(.+)(?:\.php)$/i', $name, $matches)) {
            $name = $matches[1];
        }

        if (!preg_match('/Bridge$/i', $name)) {
            $name = sprintf('%sBridge', $name);
        }

        return $name;
    }

    /**
     * Extracts the short class name from a FQCN, or returns it as-is
     * for legacy global-namespace names.
     *
     * Example: 'RSSBridge\Bridges\TelegramBridge' -> 'TelegramBridge'
     * Example: 'TelegramBridge' -> 'TelegramBridge'
     *
     * @param string $className Full class name
     */
    public function getShortClassName(string $className): string
    {
        if (str_contains($className, '\\')) {
            $parts = explode('\\', $className);
            return end($parts);
        }
        return $className;
    }

    /**
     * Returns all discovered bridge class names, sorted alphabetically.
     *
     * @return string[]
     */
    public function getBridgeClassNames(): array
    {
        return $this->bridgeClassNames;
    }

    /**
     * Returns the list of bridge names from configuration that were not found
     * during the filesystem scan.
     *
     * @return string[]
     */
    public function getMissingEnabledBridges(): array
    {
        return $this->missingEnabledBridges;
    }
}
