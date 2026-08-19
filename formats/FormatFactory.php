<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

/**
 * Factory for creating format instances.
 *
 * Maps format names to PSR-4 classes and handles both
 * built-in formats and user-provided custom formats.
 */
final class FormatFactory
{
    /**
     * Built-in format map: short name => PSR-4 class.
     *
     * @var array<string, class-string<FormatInterface>>
     */
    private const BUILTIN_FORMATS = [
        'Atom'      => AtomFormat::class,
        'Html'      => HtmlFormat::class,
        'Json'      => JsonFormat::class,
        'Mrss'      => MrssFormat::class,
        'Plaintext' => PlaintextFormat::class,
        'Sfeed'     => SfeedFormat::class,
    ];

    /**
     * List of all available format names (built-in + custom).
     *
     * @var string[]
     */
    private array $formatNames = [];

    public function __construct()
    {
        // Start with built-in formats
        $this->formatNames = array_keys(self::BUILTIN_FORMATS);

        // Scan formats directory for any additional (custom) formats
        // that aren't in the built-in list
        $formatsDir = __DIR__;
        if (is_dir($formatsDir)) {
            $iterator = new \FilesystemIterator($formatsDir);
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (preg_match('/^([A-Z][a-zA-Z0-9]*)Format\.php$/', $file->getFilename(), $m)) {
                    $name = $m[1];
                    if (!in_array($name, $this->formatNames, true)) {
                        $this->formatNames[] = $name;
                    }
                }
            }
        }

        sort($this->formatNames);
    }

    /**
     * Create a format instance by name.
     *
     * @throws \InvalidArgumentException if name is invalid or unknown
     */
    public function create(string $name): FormatInterface
    {
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Format name invalid: %s', $name));
        }

        $normalizedName = $this->normalizeName($name);

        if ($normalizedName === null) {
            throw new \InvalidArgumentException(sprintf('Unknown format: %s', $name));
        }

        // Try built-in formats first (PSR-4)
        if (isset(self::BUILTIN_FORMATS[$normalizedName])) {
            $className = self::BUILTIN_FORMATS[$normalizedName];
            return new $className();
        }

        // Fallback: try to instantiate custom format from same namespace
        $customClassName = __NAMESPACE__ . '\\' . $normalizedName . 'Format';
        if (class_exists($customClassName)) {
            return new $customClassName();
        }

        throw new \InvalidArgumentException(sprintf('Unknown format: %s', $name));
    }

    /**
     * Get list of all available format names.
     *
     * @return string[]
     */
    public function getFormatNames(): array
    {
        return $this->formatNames;
    }

    /**
     * Normalize a format name to its canonical form.
     *
     * Strips 'Format' suffix and '.php' extension, then
     * matches case-insensitively against known formats.
     *
     * @return string|null The canonical name, or null if not found
     */
    private function normalizeName(string $name): ?string
    {
        // Strip .php extension
        if (preg_match('/^(.+)\.php$/i', $name, $matches)) {
            $name = $matches[1];
        }

        // Strip 'Format' suffix
        if (preg_match('/^(.+)Format$/i', $name, $matches)) {
            $name = $matches[1];
        }

        // Capitalize first letter
        $name = ucfirst(strtolower($name));

        // Case-insensitive lookup
        $nameLower = strtolower($name);
        foreach ($this->formatNames as $known) {
            if (strtolower($known) === $nameLower) {
                return $known;
            }
        }

        return null;
    }
}
