<?php

declare(strict_types=1);

final class ProxyFactory
{
    private static array $instances = [];

    public static function fromConfig(): ProxyInterface
    {
        $proxyType = getenv('RSS_BRIDGE_BROWSER_PROXY_TYPE') 
            ?: Configuration::getConfig('browser_proxy', 'type');
        
        if (!$proxyType || $proxyType === 'none' || $proxyType === 'direct') {
            return self::create('Direct', []);
        }

        $config = [
            'url' => getenv('FLARESOLVERR_URL') 
                ?: Configuration::getConfig('browser_proxy', 'url'),
            'session_name' => getenv('FLARESOLVERR_SESSION_NAME') 
                ?: Configuration::getConfig('browser_proxy', 'session_name'),
        ];

        return self::create($proxyType, $config);
    }

    public static function create(string $type, array $config = []): ProxyInterface
    {
        if (!isset(self::$instances[$type])) {
            $className = ucfirst($type) . 'Proxy';
            
            if (!class_exists($className)) {
                throw new \RuntimeException("Proxy class not found: {$className}");
            }

            self::$instances[$type] = new $className($config);
        }

        return self::$instances[$type];
    }
}