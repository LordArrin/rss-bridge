<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;

/**
 * Factory for creating and managing bridge instances.
 *
 * This class is responsible for scanning the bridges-v2 directory, resolving
 * bridge class names from short names or FQCNs, and safely instantiating
 * bridge objects.
 *
 * It uses an isolated "sandbox" process to detect fatal compile errors
 * (like signature mismatches) before loading them into the main process,
 * preventing the entire application from crashing.
 */
final class BridgeFactory
{
    private CacheInterface $cache;
    private \Logger $logger;

    /**
     * Array of all available bridge class names (FQCN).
     * Example: ['RSSBridge\Bridges\TelegramBridge', 'RSSBridge\Bridges\YoutubeBridge', ...]
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

    public function __construct(CacheInterface $cache, \Logger $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;

        $this->scanBridges();
        $this->loadEnabledBridges();
    }

    /**
     * Scans the bridges-v2 directory and populates bridgeClassNames and shortNameMap.
     */
    private function scanBridges(): void
    {
        $this->bridgeClassNames = [];
        $this->shortNameMap = [];

        $v2Dir = __DIR__ . '/../bridges-v2/';
        if (is_dir($v2Dir) === false) {
            return;
        }

        foreach (scandir($v2Dir) as $file) {
            if (preg_match('/^([^.]+Bridge)\.php$/U', $file, $m) !== 1) {
                continue;
            }

            $shortName = $m[1];
            $fqcn = 'RSSBridge\\Bridges\\' . $shortName;

            $this->bridgeClassNames[] = $fqcn;
            $this->shortNameMap[strtolower($shortName)] = $fqcn;
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
            if ($bridgeClassName !== null) {
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
     *
     * @throws \Exception If the bridge cannot be found, loaded, or instantiated
     */
    public function create(string $name): BridgeAbstract
    {
        $resolved = $this->createBridgeClassName($name);
        if ($resolved === null) {
            throw new \Exception(sprintf('Bridge class not found: %s', $name));
        }

        if (class_exists($resolved, false) === false) {
            $file = $this->getBridgeFilePath($resolved);
            if ($file !== null && is_readable($file) === true) {
                $this->loadBridgeInSandbox($file);
                include_once $file;
            }
        }

        if (class_exists($resolved, false) === false) {
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
     * This prevents the main process from crashing when a bridge
     * has incompatible type declarations with its parent abstract class.
     *
     * @param string $file Absolute path to the bridge PHP file
     *
     * @throws \Exception If the sandbox check fails
     */
    private function loadBridgeInSandbox(string $file): void
    {
        $testScript = tempnam(sys_get_temp_dir(), 'rssbridge_sandbox_') . '.php';
        $vendorPath = realpath(__DIR__ . '/../vendor/autoload.php');

        $code = "<?php\n";
        if ($vendorPath !== false) {
            $code .= "require '" . addslashes($vendorPath) . "';\n";
        }

        $code .= "try {\n";
        $code .= "    require '" . addslashes($file) . "';\n";
        $code .= "    echo 'SANDBOX_SUCCESS';\n";
        $code .= "} catch (\\Throwable \$e) {\n";
        $code .= "    echo 'SANDBOX_EXCEPTION: ' . \$e->getMessage() . \"\\n\";\n";
        $code .= "}\n";

        file_put_contents($testScript, $code);

        $phpBinary = self::getPhpCliBinary();

        $output = [];
        $returnVar = 0;
        exec($phpBinary . ' ' . escapeshellarg($testScript) . ' 2>&1', $output, $returnVar);
        unlink($testScript);

        $result = trim(implode("\n", $output));

        if ($returnVar !== 0 || $result !== 'SANDBOX_SUCCESS') {
            throw new \Exception('Bridge compatibility error: ' . $result);
        }
    }

    /**
     * Resolves the path to the PHP CLI binary.
     *
     * When running under PHP-FPM, PHP_BINARY points to the FPM executable
     * which cannot execute scripts from the command line. This method
     * detects the FPM environment and returns the corresponding CLI
     * binary path by stripping the "-fpm" suffix.
     *
     * Falls back to the original PHP_BINARY if a CLI version cannot be found
     * or if the application is already running in CLI mode.
     *
     * @return string Absolute path to the PHP CLI executable
     */
    private static function getPhpCliBinary(): string
    {
        $binary = PHP_BINARY;
        $basename = basename($binary);

        if (str_contains($basename, 'fpm') === false) {
            return $binary;
        }

        $candidates = [];

        $cliName = str_replace(['fpm-', '-fpm'], '', $basename);
        $dir = dirname($binary);
        $candidates[] = $dir . DIRECTORY_SEPARATOR . $cliName;

        $candidates[] = '/usr/bin/' . $cliName;
        $candidates[] = '/usr/local/bin/' . $cliName;

        $genericCliName = preg_replace('/[\d.]+/', '', $cliName);
        if ($genericCliName !== $cliName) {
            $candidates[] = $dir . DIRECTORY_SEPARATOR . $genericCliName;
            $candidates[] = '/usr/bin/' . $genericCliName;
        }

        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';

        foreach ($candidates as $candidate) {
            if (is_executable($candidate) === true) {
                return $candidate;
            }
        }

        return $binary;
    }

    /**
     * Resolves the filesystem path to a bridge file by its class name.
     *
     * @param string $className Full class name (FQCN)
     * @return string|null Absolute path to the file, or null if not found
     */
    private function getBridgeFilePath(string $className): ?string
    {
        $shortName = $this->getShortClassName($className);

        $v2File = __DIR__ . '/../bridges-v2/' . $shortName . '.php';
        if (file_exists($v2File) === true) {
            return $v2File;
        }

        return null;
    }

    /**
     * Checks whether a bridge is enabled in the configuration.
     *
     * @param string $bridgeName Full class name (FQCN)
     */
    public function isEnabled(string $bridgeName): bool
    {
        return in_array($bridgeName, $this->enabledBridges, true);
    }

    /**
     * Resolves a bridge class name from a short name or FQCN.
     *
     * @param string $bridgeName Any accepted bridge name format
     * @return string|null The full class name, or null if not found
     */
    public function createBridgeClassName(string $bridgeName): ?string
    {
        $name = self::normalizeBridgeName($bridgeName);
        $nameLower = strtolower($name);

        if (isset($this->shortNameMap[$nameLower]) === true) {
            return $this->shortNameMap[$nameLower];
        }

        if (class_exists($bridgeName, false) === true) {
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
        if (str_contains($name, '\\') === true) {
            $parts = explode('\\', $name);
            $name = end($parts);
        }

        if (preg_match('/(.+)(?:\.php)$/i', $name, $matches) === 1) {
            $name = $matches[1];
        }

        if (preg_match('/Bridge$/i', $name) === 0) {
            $name = sprintf('%sBridge', $name);
        }

        return $name;
    }

    /**
     * Extracts the short class name from a FQCN.
     *
     * Example: 'RSSBridge\Bridges\TelegramBridge' -> 'TelegramBridge'
     *
     * @param string $className Full class name
     */
    public function getShortClassName(string $className): string
    {
        if (str_contains($className, '\\') === true) {
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
