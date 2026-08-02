<?php

declare(strict_types=1);

namespace RssBridge\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests: fetch real data from bridges.
 * Run separately: vendor/bin/phpunit --testsuite=integration
 * These are slow and depend on external services.
 */
final class BridgeHttpTest extends TestCase
{
    private const TIMEOUT = 30;

    #[DataProvider('httpBridges')]
    public function testBridgeReturnsItems(string $class, array $inputs): void
    {
        $bridge = new $class(new \NullCache(), new \NullLogger());
        $bridge->setInputs($inputs);
        $bridge->collectData();

        $items = $bridge->getItems();
        $this->assertIsArray($items);
        $this->assertNotEmpty($items, "$class returned no items");

        // Every item must have at least a title or content
        foreach ($items as $i => $item) {
            $this->assertTrue(
                isset($item['title']) || isset($item['content']),
                "$class item #$i has neither title nor content"
            );
        }
    }

    public static function httpBridges(): array
    {
        return [
            'FirefoxAddons' => [
                '\\FirefoxAddonsBridge',
                ['id' => 'ublock-origin'],
            ],
            'GitHub Releases' => [
                '\\GithubReleaseBridge',
                ['u' => 'rss-bridge', 'p' => 'rss-bridge'],
            ],
        ];
    }
}