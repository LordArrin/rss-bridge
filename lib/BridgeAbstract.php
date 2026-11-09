<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;
use RSSBridge\ParameterValidator;
use RSSBridge\Http\Response;

abstract class BridgeAbstract
{
    public const NAME = null;
    public const URI = null;
    public const DESCRIPTION = 'No description provided';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;
    public const CONFIGURATION = [];
    public const PARAMETERS = [];

    protected const LIMIT = [
        'name'          => 'Limit',
        'type'          => 'number',
        'title'         => 'Maximum number of items to return',
    ];

    /**
     * @var list<array<string, mixed>>
     */
    protected array $items = [];

    /**
     * @var array<string, array<string, array{value: mixed}>>
     */
    protected array $inputs = [];
    protected ?string $queriedContext = null;

    /**
     * @var array<string, mixed>
     */
    private array $configuration = [];

    protected ?CacheInterface $cache = null;
    protected ?\RSSBridge\Logger\Logger $logger = null;
    protected ?Response $rawOutput = null;

    public function __construct(?CacheInterface $cache = null, ?\RSSBridge\Logger\Logger $logger = null)
    {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    abstract public function collectData(): void;

    /**
     * @return array{name: string, uri: string, icon: string}
     */
    public function getFeed(): array
    {
        return [
            'name'          => $this->getName(),
            'uri'           => $this->getURI(),
            'icon'          => $this->getIcon(),
        ];
    }

    public function getName(): string
    {
        return static::NAME ?? $this->getShortName();
    }

    public function getURI(): string
    {
        return static::URI ?? 'https://github.com/LordArrin/rss-bridge/';
    }

    public function getIcon(): string
    {
        if (is_string(static::URI) === true && static::URI !== '') {
            return rtrim(static::URI, '/') . '/favicon.ico';
        }
        return '';
    }

    public function getOption(string $name): mixed
    {
        return $this->configuration[$name] ?? null;
    }

    public function getDescription(): string
    {
        return static::DESCRIPTION;
    }

    public function getMaintainer(): string
    {
        return static::MAINTAINER;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function getParameters(): array
    {
        return static::PARAMETERS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getCacheTimeout(): int
    {
        return static::CACHE_TIMEOUT;
    }

    public function loadConfiguration(): void
    {
        foreach (static::CONFIGURATION as $optionName => $optionValue) {
            $section = $this->getShortName();
            $configurationOption = Configuration::getConfig($section, $optionName);

            if ($configurationOption !== null) {
                $this->configuration[$optionName] = $configurationOption;
                continue;
            }

            if (isset($optionValue['required']) === true && $optionValue['required'] === true) {
                throw new \Exception(sprintf('Missing configuration option: %s', $optionName));
            } elseif (isset($optionValue['defaultValue']) === true) {
                $this->configuration[$optionName] = $optionValue['defaultValue'];
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function setInput(array $input): void
    {
        $contextName = $input['context'] ?? null;
        if ($contextName !== null && is_string($contextName) === true) {
            $this->queriedContext = $contextName;
            unset($input['context']);
        }

        $parameters = $this->getParameters();

        if ($parameters === []) {
            if ($input !== []) {
                throwClientException('Unexpected parameters');
            }
            return;
        }

        $validator = new ParameterValidator();
        $errors = $validator->validateInput($input, $parameters);
        if ($errors !== []) {
            $invalidParameterKeys = array_column($errors, 'name');
            throwClientException(sprintf('Invalid parameters value(s): %s', implode(', ', $invalidParameterKeys)));
        }

        if ($this->queriedContext === null) {
            $queriedContext = $validator->getQueriedContext($input, $parameters);

            if ($queriedContext === false) {
                throw new \Exception('Mixed context parameters');
            }

            // Исправление: разрешаем как string, так и int (например, 0 для контекста по умолчанию)
            if (is_string($queriedContext) === true || is_int($queriedContext) === true) {
                $this->queriedContext = (string) $queriedContext;
            }
        }

        if ($this->queriedContext === null) {
            throwClientException('Required parameter(s) missing');
        }

        $this->setInputWithContext($input, $this->queriedContext);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function setInputWithContext(array $input, string $queriedContext): void
    {
        $parameters = $this->getParameters();

        foreach ($input as $name => $value) {
            foreach ($parameters as $context => $set) {
                if (array_key_exists($name, $parameters[$context]) === true) {
                    $this->inputs[$context][$name]['value'] = $value;
                }
            }
        }

        $contextNames = [$queriedContext];
        if (array_key_exists('global', $parameters) === true) {
            $contextNames[] = 'global';
        }

        foreach ($contextNames as $context) {
            if (isset($parameters[$context]) === false) {
                continue;
            }

            foreach ($parameters[$context] as $name => $parameter) {
                if (isset($this->inputs[$context][$name]['value']) === true) {
                    continue;
                }

                $type = $parameter['type'] ?? 'text';

                switch ($type) {
                    case 'checkbox':
                        $this->inputs[$context][$name]['value'] = $input[$context][$name]['value'] ?? false;
                        break;
                    case 'list':
                        if (isset($parameter['defaultValue']) === false) {
                            $firstItem = reset($parameter['values']);
                            if (is_array($firstItem) === true) {
                                $firstItem = reset($firstItem);
                            }
                            $this->inputs[$context][$name]['value'] = $firstItem;
                        } else {
                            $this->inputs[$context][$name]['value'] = $parameter['defaultValue'];
                        }
                        break;
                    default:
                        if (isset($parameter['defaultValue']) === true) {
                            $this->inputs[$context][$name]['value'] = $parameter['defaultValue'];
                        }
                        break;
                }
            }
        }

        if (array_key_exists('global', $parameters) === true) {
            foreach ($parameters['global'] as $name => $parameter) {
                if (isset($input[$name]) === true) {
                    $value = $input[$name];
                } else {
                    if (($parameter['type'] ?? null) === 'checkbox') {
                        $value = false;
                    } elseif (isset($parameter['defaultValue']) === true) {
                        $value = $parameter['defaultValue'];
                    } else {
                        continue;
                    }
                }
                $this->inputs[$queriedContext][$name]['value'] = $value;
            }
        }

        if (isset($this->inputs[$queriedContext]) === true) {
            $this->inputs = [
                $queriedContext => $this->inputs[$queriedContext],
            ];
        } else {
            $this->inputs = [];
        }
    }

    protected function getInput(string $input): mixed
    {
        if ($this->queriedContext === null) {
            return null;
        }
        return $this->inputs[$this->queriedContext][$input]['value'] ?? null;
    }

    public function getKey(string $input): ?string
    {
        if ($this->queriedContext === null) {
            return null;
        }
        if (isset($this->inputs[$this->queriedContext][$input]['value']) === false) {
            return null;
        }

        $contexts = $this->getParameters();
        $contextName = $this->queriedContext;

        if (array_key_exists('global', $contexts) === true && array_key_exists($input, $contexts['global']) === true) {
            $contextName = 'global';
        }

        $needle = (string)$this->inputs[$this->queriedContext][$input]['value'];
        $values = $contexts[$contextName][$input]['values'] ?? [];
        if (is_array($values) === false) {
            return null;
        }
        foreach ($values as $first_level_key => $first_level_value) {
            if (is_array($first_level_value) === false && $needle === (string)$first_level_value) {
                return (string)$first_level_key;
            } elseif (is_array($first_level_value) === true) {
                foreach ($first_level_value as $second_level_key => $second_level_value) {
                    if ($needle === (string)$second_level_value) {
                        return (string)$second_level_key;
                    }
                }
            }
        }
        return null;
    }

    protected function loadCacheValue(string $key, mixed $default = null): mixed
    {
        if ($this->cache === null) {
            return $default;
        }
        return $this->cache->get($this->getShortName() . '_' . $key, $default);
    }

    protected function saveCacheValue(string $key, mixed $value, int $ttl = 86400): void
    {
        if ($this->cache === null) {
            return;
        }
        $this->cache->set($this->getShortName() . '_' . $key, $value, $ttl);
    }

    public function getShortName(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }
}
