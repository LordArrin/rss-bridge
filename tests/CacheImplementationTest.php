<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSSBridge\Caches\CacheInterface;

final class CacheImplementationTest extends TestCase
{
    public function testAllCachesImplementInterface(): void
    {
        $cacheDir = __DIR__ . '/../caches/';
        $files = glob($cacheDir . '*Cache.php');

        foreach ($files as $file) {
            $className = 'RSSBridge\\Caches\\' . basename($file, '.php');

            $this->assertTrue(
                class_exists($className),
                sprintf('Class %s must exist', $className)
            );

            $this->assertTrue(
                is_subclass_of($className, CacheInterface::class),
                sprintf('Class %s must implement CacheInterface', $className)
            );
        }
    }
}
