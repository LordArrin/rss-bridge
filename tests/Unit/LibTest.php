<?php

declare(strict_types=1);

namespace RssBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LibTest extends TestCase
{
    // --- URL validation ------------------------------------------------

    #[DataProvider('validUrls')]
    public function testUrlValidateAcceptsValid(string $url): void
    {
        $this->assertTrue(\Url::validate($url), "Should accept: $url");
    }

    #[DataProvider('invalidUrls')]
    public function testUrlValidateRejectsInvalid(string $url): void
    {
        $this->assertFalse(\Url::validate($url), "Should reject: $url");
    }

    public static function validUrls(): array
    {
        return [
            'http' => ['http://example.com'],
            'https' => ['https://example.com'],
            'with path' => ['https://example.com/path/to/page'],
            'with query' => ['https://example.com/page?q=test&lang=en'],
            'with port' => ['https://example.com:8080/api'],
        ];
    }

    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'no scheme' => ['example.com'],
            'ftp' => ['ftp://example.com'],
            'javascript' => ['javascript:alert(1)'],
            'spaces' => ['https://exam ple.com'],
        ];
    }

    // --- Configuration -------------------------------------------------

    public function testConfigReturnsDefaults(): void
    {
        $this->assertSame('prod', \Configuration::getConfig('system', 'env'));
        $this->assertSame('UTC', \Configuration::getConfig('system', 'timezone'));
    }

    public function testConfigInvalidSectionReturnsNull(): void
    {
        $this->assertNull(\Configuration::getConfig('nonexistent_section', 'key'));
    }

    // --- sanitize_root -------------------------------------------------

    public function testSanitizeRoot(): void
    {
        $root = dirname(__DIR__, 2);
        $input = $root . '/lib/http.php';
        $result = sanitize_root($input);
        $this->assertStringNotContainsString($root, $result);
        $this->assertStringContainsString('lib/http.php', $result);
    }

    // --- str_get_html (simple_html_dom wrapper) ------------------------

    public function testStrGetHtml(): void
    {
        $html = str_get_html('<div><p>Hello</p></div>');
        $this->assertNotFalse($html);
        $this->assertSame('Hello', $html->find('p', 0)->plaintext);
    }

    // --- urljoin -------------------------------------------------------

    #[DataProvider('urljoinCases')]
    public function testUrljoin(string $base, string $relative, string $expected): void
    {
        $this->assertSame($expected, urljoin($base, $relative));
    }

    public static function urljoinCases(): array
    {
        return [
            'absolute' => ['https://example.com', '/path', 'https://example.com/path'],
            'relative' => ['https://example.com/dir/', 'file.html', 'https://example.com/dir/file.html'],
            'parent' => ['https://example.com/a/b/', '../c', 'https://example.com/a/c'],
            'full url' => ['https://example.com', 'https://other.com/x', 'https://other.com/x'],
        ];
    }
}