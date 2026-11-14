<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Logger\Logger;
use RSSBridge\Caches\CacheInterface;

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
 *
 * Bridge filtering is controlled by the ENABLED_BRIDGES and DISABLED_BRIDGES
 * environment variables, parsed as comma-separated lists.
 */
final class BridgeFactory
{
    private CacheInterface $cache;
    private Logger $logger;

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
     * List of bridge class names that are enabled after applying filters.
     *
     * @var string[]
     */
    private array $enabledBridges = [];

    /**
     * List of enabled bridges from configuration that were not found on disk.
     *
     * @var string[]
     */
    private array $missingEnabledBridges = [];

    /**
     * Per-process sandbox verification results, keyed by path + mtime + size.
     * Avoids re-running the subprocess for unchanged files within a worker;
     * editing a file changes mtime/size and invalidates the entry.
     *
     * @var array<string, bool>
     */
    private static array $sandboxCache = [];

    public function __construct(CacheInterface $cache, Logger $logger)
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

        $files = scandir($v2Dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
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
     * Loads the list of enabled bridges from environment variables.
     *
     * Reads ENABLED_BRIDGES and DISABLED_BRIDGES (comma-separated lists).
     * The special value '*' in ENABLED_BRIDGES enables all discovered bridges.
     * Bridges listed in ENABLED_BRIDGES but not found on disk are logged
     * and stored in {@see getMissingEnabledBridges()}.
     *
     * @throws \Exception If no bridges are enabled after filtering
     */
    private function loadEnabledBridges(): void
    {
        $enabledRaw = getenv('ENABLED_BRIDGES');
        $disabledRaw = getenv('DISABLED_BRIDGES');

        $enabledList = $this->parseBridgeList($enabledRaw === false ? '' : $enabledRaw);
        $disabledList = $this->parseBridgeList($disabledRaw === false ? '' : $disabledRaw);

        $resolvedEnabled = [];

        if (in_array('*', $enabledList, true) === true) {
            $resolvedEnabled = $this->bridgeClassNames;
        } else {
            foreach ($enabledList as $bridgeName) {
                $bridgeClassName = $this->createBridgeClassName($bridgeName);
                if ($bridgeClassName !== null) {
                    $resolvedEnabled[] = $bridgeClassName;
                } else {
                    $this->missingEnabledBridges[] = $bridgeName;
                    $this->logger->info(sprintf('Bridge listed in ENABLED_BRIDGES not found: %s', $bridgeName));
                }
            }
        }

        $resolvedDisabled = [];
        foreach ($disabledList as $bridgeName) {
            $bridgeClassName = $this->createBridgeClassName($bridgeName);
            if ($bridgeClassName !== null) {
                $resolvedDisabled[] = $bridgeClassName;
            } else {
                $this->logger->info(sprintf('Bridge listed in DISABLED_BRIDGES not found (ignored): %s', $bridgeName));
            }
        }

        $this->enabledBridges = array_values(array_diff($resolvedEnabled, $resolvedDisabled));

        if ($this->enabledBridges === []) {
            throw new \Exception('No bridges are enabled after applying ENABLED_BRIDGES and DISABLED_BRIDGES filters');
        }
    }

    /**
     * Parses a comma-separated string of bridge names into an array.
     *
     * @param string $raw The raw string from environment variable
     * @return string[] Array of trimmed, non-empty strings
     */
    private function parseBridgeList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = explode(',', $raw);
        $list = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $list[] = $part;
            }
        }
        return $list;
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
            if ($reflection->isSubclassOf(BridgeAbstract::class) === false) {
                throw new \Exception(sprintf('Class %s does not extend BridgeAbstract', $resolved));
            }
            $constructor = $reflection->getConstructor();

            if ($constructor !== null && count($constructor->getParameters()) >= 2) {
                $instance = new $resolved($this->cache, $this->logger);
            } else {
                $instance = new $resolved();
            }
            assert($instance instanceof BridgeAbstract);
            return $instance;
        } catch (\Throwable $e) {
            throw new \Exception(sprintf('Cannot instantiate bridge %s: %s', $resolved, $e->getMessage()));
        }
    }

    /**
     * Loads a bridge file inside an isolated PHP subprocess to detect
     * fatal compile errors (e.g. signature mismatches with parent classes).
     *
     * The verdict is written to a dedicated result file, NOT parsed from stdout:
     * the new Dom\* parser and PHP 8.5 deprecations legitimately emit warnings,
     * and those must not mark an otherwise working bridge as broken.
     *
     * @param string $file Absolute path to the bridge PHP file
     *
     * @throws \Exception If the sandbox check fails
     */
    private function loadBridgeInSandbox(string $file): void
    {
        $real = realpath($file);
        $mtime = @filemtime($file);
        $size = @filesize($file);
        $cacheKey = ($real !== false ? $real : $file)
            . '|' . ($mtime === false ? 0 : $mtime)
            . '|' . ($size === false ? 0 : $size);

        if (isset(self::$sandboxCache[$cacheKey]) === true) {
            if (self::$sandboxCache[$cacheKey] === true) {
                return;
            }
            throw new \Exception('Bridge compatibility error (cached): ' . $file);
        }

        // tempnam() creates the base file; rename it to the .php script so we do
        // not leak an orphan temp file on every load (the old code unlinked only
        // the .php variant and left the base behind).
        $tmpBase = tempnam(sys_get_temp_dir(), 'rssbridge_sandbox_');
        if ($tmpBase === false) {
            throw new \Exception('Unable to create sandbox temp file');
        }
        $testScript = $tmpBase . '.php';
        $resultFile = $tmpBase . '.result';
        if (@rename($tmpBase, $testScript) === false) {
            @unlink($tmpBase);
            throw new \Exception('Unable to prepare sandbox script');
        }

        try {
            $vendorPath = realpath(__DIR__ . '/../vendor/autoload.php');

            $code = "<?php\n";
            $code .= "set_time_limit(30);\n";
            if ($vendorPath !== false) {
                $code .= "require '" . addslashes($vendorPath) . "';\n";
            }
            $code .= "try {\n";
            $code .= "    require '" . addslashes($file) . "';\n";
            $code .= "    file_put_contents('" . addslashes($resultFile) . "', 'SANDBOX_SUCCESS');\n";
            $code .= "} catch (\\Throwable \$e) {\n";
            $code .= "    file_put_contents('" . addslashes($resultFile) . "', 'SANDBOX_EXCEPTION: ' . \$e->getMessage());\n";
            $code .= "}\n";

            if (file_put_contents($testScript, $code) === false) {
                throw new \Exception('Unable to write sandbox script');
            }

            $phpBinary = self::getPhpCliBinary();
            $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($testScript) . ' 2>&1';

            // Optional wall-clock guard when coreutils/busybox timeout is present.
            $timeoutBin = is_executable('/usr/bin/timeout') === true ? '/usr/bin/timeout' : (is_executable('/bin/timeout') === true ? '/bin/timeout' : null);
            if ($timeoutBin !== null) {
                $cmd = $timeoutBin . ' 30 ' . $cmd;
            }

            $output = [];
            $returnVar = 0;

            // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
            exec($cmd, $output, $returnVar);

            $result = is_file($resultFile) === true ? (string) file_get_contents($resultFile) : '';
            $stdout = trim(implode("\n", $output));

            if ($result === 'SANDBOX_SUCCESS' && $returnVar === 0) {
                if ($stdout !== '') {
                    // Warnings/deprecations are not fatal: log, do not mark broken.
                    $this->logger->debug(sprintf('[sandbox] %s emitted output: %s', basename($file), $stdout));
                }
                self::$sandboxCache[$cacheKey] = true;
                return;
            }

            self::$sandboxCache[$cacheKey] = false;
            $detail = $result !== '' ? $result : ($stdout !== '' ? $stdout : 'no result (fatal compile error?)');
            throw new \Exception('Bridge compatibility error: ' . $detail);
        } finally {
            @unlink($testScript);
            @unlink($resultFile);
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
        $pos = strrpos($name, '\\');
        if ($pos !== false) {
            $name = substr($name, $pos + 1);
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
        $pos = strrpos($className, '\\');
        return $pos === false ? $className : substr($className, $pos + 1);
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
