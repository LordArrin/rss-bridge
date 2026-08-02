<?php

declare(strict_types=1);

namespace RssBridge\Tests\Unit;

use BridgeAbstract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates that every bridge in bridges/ satisfies the contract
 * defined by BridgeAbstract.
 */
final class BridgeContractTest extends TestCase
{
    private BridgeAbstract $bridge;

    // --- Class structure -----------------------------------------------

    #[DataProvider('bridges')]
    public function testExtendsBridgeAbstract(string $class): void
    {
        $bridge = $this->instantiate($class);
        $this->assertInstanceOf(BridgeAbstract::class, $bridge);
    }

    #[DataProvider('bridges')]
    public function testClassNameConvention(string $class): void
    {
        $short = ltrim($class, '\\');
        $this->assertStringEndsWith('Bridge', $short);
        $this->assertSame(ucfirst($short), $short, 'Class name must start with uppercase');
        $this->assertStringNotContainsString(' ', $short, 'Class name must not contain spaces');
    }

    // --- Constants -----------------------------------------------------

    #[DataProvider('bridges')]
    public function testRequiredConstants(string $class): void
    {
        $bridge = $this->instantiate($class);

        $this->assertIsString($bridge::NAME);
        $this->assertNotEmpty($bridge::NAME, sprintf('%s::NAME is empty', $class));

        $this->assertIsString($bridge::URI);
        $this->assertNotEmpty($bridge::URI, sprintf('%s::URI is empty', $class));

        $this->assertIsString($bridge::DESCRIPTION);
        $this->assertNotEmpty($bridge::DESCRIPTION, sprintf('%s::DESCRIPTION is empty', $class));

        $this->assertIsString($bridge::MAINTAINER);
        $this->assertNotEmpty($bridge::MAINTAINER, sprintf('%s::MAINTAINER is empty', $class));

        $this->assertIsArray($bridge::PARAMETERS);

        $this->assertIsInt($bridge::CACHE_TIMEOUT);
        $this->assertGreaterThanOrEqual(0, $bridge::CACHE_TIMEOUT, sprintf('%s::CACHE_TIMEOUT < 0', $class));
    }

    #[DataProvider('bridges')]
    public function testUriIsValid(string $class): void
    {
        $bridge = $this->instantiate($class);
        $uri = $bridge::URI;
        $this->assertNotFalse(
            filter_var($uri, FILTER_VALIDATE_URL),
            sprintf('%s::URI is not a valid URL: %s', $class, $uri)
        );
    }

    // --- Parameters schema ---------------------------------------------

    #[DataProvider('bridges')]
    public function testParametersSchema(string $class): void
    {
        $bridge = $this->instantiate($class);
        $allowedTypes = ['text', 'number', 'list', 'checkbox'];

        foreach ($bridge::PARAMETERS as $context => $params) {
            if (empty($params)) {
                continue;
            }

            $this->assertIsArray($params, "$class: context '$context' is not an array");

            foreach ($params as $field => $options) {
                $prefix = "$class [$context.$field]";

                $this->assertIsString($field, "$prefix: field id is not a string");
                $this->assertNotEmpty($field, "$prefix: field id is empty");

                $this->assertArrayHasKey('name', $options, "$prefix: missing 'name'");
                $this->assertIsString($options['name'], "$prefix: 'name' is not a string");
                $this->assertNotEmpty($options['name'], "$prefix: 'name' is empty");

                if (isset($options['type'])) {
                    $this->assertContains(
                        $options['type'],
                        $allowedTypes,
                        "$prefix: invalid type '{$options['type']}'"
                    );

                    if ($options['type'] === 'list') {
                        $this->assertArrayHasKey('values', $options, "$prefix: list without 'values'");
                        $this->assertIsArray($options['values'], "$prefix: 'values' is not an array");
                        $this->assertNotEmpty($options['values'], "$prefix: 'values' is empty");
                    }
                }

                if (isset($options['required'])) {
                    $this->assertIsBool($options['required'], "$prefix: 'required' is not bool");
                }
            }
        }
    }

    // --- Methods -------------------------------------------------------

    #[DataProvider('bridges')]
    public function testPublicMethods(string $class): void
    {
        $bridge = $this->instantiate($class);

        $this->assertIsString($bridge->getName());
        $this->assertNotEmpty($bridge->getName());

        $this->assertIsString($bridge->getDescription());
        $this->assertNotEmpty($bridge->getDescription());

        $this->assertIsString($bridge->getMaintainer());
        $this->assertNotEmpty($bridge->getMaintainer());

        $this->assertIsString($bridge->getURI());
        $this->assertNotEmpty($bridge->getURI());

        $this->assertIsString($bridge->getIcon());
    }

    // --- detectParameters ----------------------------------------------

    #[DataProvider('bridges')]
    public function testDetectParameters(string $class): void
    {
        $bridge = $this->instantiate($class);

        if (empty($bridge::TEST_DETECT_PARAMETERS)) {
            $this->markTestSkipped("$class has no TEST_DETECT_PARAMETERS");
        }

        foreach ($bridge::TEST_DETECT_PARAMETERS as $url => $expected) {
            $result = $bridge->detectParameters($url);
            $this->assertSame(
                $expected,
                $result,
                "$class::detectParameters('$url') mismatch"
            );
        }
    }

    // --- Data provider -------------------------------------------------

    public static function bridges(): array
    {
        $result = [];
        foreach (glob(__DIR__ . '/../../bridges/*Bridge.php') as $file) {
            $class = '\\' . basename($file, '.php');
            $result[basename($file, '.php')] = [$class];
        }
        return $result;
    }

    // --- Helper --------------------------------------------------------

    private function instantiate(string $class): BridgeAbstract
    {
        $this->assertTrue(class_exists($class), "Class $class does not exist");
        return new $class(new \NullCache(), new \NullLogger());
    }
}