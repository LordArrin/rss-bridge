<?php

declare(strict_types=1);

namespace RssBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/rssbridge_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir);
    }

    // --- NullCache -----------------------------------------------------

    public function testNullCacheAlwaysReturnsNull(): void
    {
        $cache = new \NullCache();
        $cache->set('key', 'value');
        $this->assertNull($cache->get('key'));
    }

    // --- FileCache -----------------------------------------------------

    public function testFileCacheSetGet(): void
    {
        $cache = new \FileCache(new \NullLogger(), [
            'path' => $this->cacheDir,
            'enable_purge' => true,
        ]);

        $cache->set('test_key', ['data' => 'hello']);
        $this->assertSame(['data' => 'hello'], $cache->get('test_key'));
    }

    public function testFileCacheMissReturnsNull(): void
    {
        $cache = new \FileCache(new \NullLogger(), [
            'path' => $this->cacheDir,
            'enable_purge' => true,
        ]);

        $this->assertNull($cache->get('nonexistent'));
    }

    public function testFileCachePurge(): void
    {
        $cache = new \FileCache(new \NullLogger(), [
            'path' => $this->cacheDir,
            'enable_purge' => true,
        ]);

        $cache->set('purge_me', 'data');
        $cache->purge();
        $this->assertNull($cache->get('purge_me'));
    }

    // --- SQLiteCache ---------------------------------------------------

    public function testSqliteCacheSetGet(): void
    {
        $cache = new \SQLiteCache(new \NullLogger(), [
            'file' => $this->cacheDir . '/test.sqlite',
            'timeout' => 5000,
            'enable_purge' => true,
        ]);

        $cache->set('sqlite_key', ['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $cache->get('sqlite_key'));
    }

    public function testSqliteCacheMissReturnsNull(): void
    {
        $cache = new \SQLiteCache(new \NullLogger(), [
            'file' => $this->cacheDir . '/test.sqlite',
            'timeout' => 5000,
            'enable_purge' => true,
        ]);

        $this->assertNull($cache->get('nonexistent'));
    }

    public function testSqliteCachePurge(): void
    {
        $cache = new \SQLiteCache(new \NullLogger(), [
            'file' => $this->cacheDir . '/test.sqlite',
            'timeout' => 5000,
            'enable_purge' => true,
        ]);

        $cache->set('purge_me', 'data');
        $cache->purge();
        $this->assertNull($cache->get('purge_me'));
    }

    // --- Helper --------------------------------------------------------

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}