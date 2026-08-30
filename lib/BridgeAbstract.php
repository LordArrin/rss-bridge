<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;
use RSSBridge\ParameterValidator;

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

    protected $items = [];
    protected $inputs = [];
    protected $queriedContext = null;
    private $configuration = [];

    protected $cache;
    protected $logger;

    public function __construct($cache = null, $logger = null)
    {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    abstract public function collectData();

    public function getFeed()
    {
        return [
            'name'          => $this->getName(),
            'uri'           => $this->getURI(),
            'icon'          => $this->getIcon(),
        ];
    }

    public function getName()
    {
        return static::NAME ?? $this->getShortName();
    }

    public function getURI()
    {
        return static::URI ?? 'https://github.com/LordArrin/rss-bridge/';
    }

    public function getIcon()
    {
        if (empty(static::URI) === false) {
            return rtrim(static::URI, '/') . '/favicon.ico';
        }
        return '';
    }

    public function getOption($name)
    {
        return $this->configuration[$name] ?? null;
    }

    public function getDescription()
    {
        return static::DESCRIPTION;
    }

    public function getMaintainer()
    {
        return static::MAINTAINER;
    }

    public function getParameters()
    {
        return static::PARAMETERS;
    }

    public function getItems()
    {
        return $this->items;
    }

    public function getCacheTimeout()
    {
        return static::CACHE_TIMEOUT;
    }

    public function loadConfiguration()
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

    public function setInput(array $input)
    {
        $contextName = $input['context'] ?? null;
        if ($contextName !== null) {
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

        if (empty($this->queriedContext) === true) {
            $queriedContext = $validator->getQueriedContext($input, $parameters);
            $this->queriedContext = $queriedContext;
        }

        if ($this->queriedContext === null) {
            throwClientException('Required parameter(s) missing');
        } elseif ($this->queriedContext === false) {
            throw new \Exception('Mixed context parameters');
        }

        $this->setInputWithContext($input, $this->queriedContext);
    }

    private function setInputWithContext(array $input, $queriedContext)
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

    protected function getInput($input)
    {
        if ($this->queriedContext === null) {
            return null;
        }
        return $this->inputs[$this->queriedContext][$input]['value'] ?? null;
    }

    public function getKey($input)
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
        foreach ($contexts[$contextName][$input]['values'] as $first_level_key => $first_level_value) {
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

    protected function loadCacheValue($key, $default = null)
    {
        return $this->cache->get($this->getShortName() . '_' . $key, $default);
    }

    protected function saveCacheValue($key, $value, $ttl = 86400)
    {
        $this->cache->set($this->getShortName() . '_' . $key, $value, $ttl);
    }

    public function getShortName()
    {
        return (new \ReflectionClass($this))->getShortName();
    }
}
