<?php

declare(strict_types=1);

namespace RssBridge\Tests\Unit;

use FormatAbstract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates that every format in formats/ satisfies the contract.
 */
final class FormatContractTest extends TestCase
{
    #[DataProvider('formats')]
    public function testExtendsFormatAbstract(string $class): void
    {
        $format = new $class();
        $this->assertInstanceOf(FormatAbstract::class, $format);
    }

    #[DataProvider('formats')]
    public function testStringifyReturnsString(string $class): void
    {
        $format = new $class();
        $format->setItems([
            [
                'title' => 'Test item',
                'uri' => 'https://example.com',
                'content' => 'Hello world',
                'timestamp' => time(),
            ],
        ]);

        $output = $format->stringify();
        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    #[DataProvider('formats')]
    public function testContentTypeIsSet(string $class): void
    {
        $format = new $class();
        $this->assertIsString($format->getContentType());
        $this->assertNotEmpty($format->getContentType());
    }

    public static function formats(): array
    {
        $result = [];
        foreach (glob(__DIR__ . '/../../formats/*Format.php') as $file) {
            $class = '\\' . basename($file, '.php');
            $result[basename($file, '.php')] = [$class];
        }
        return $result;
    }
}