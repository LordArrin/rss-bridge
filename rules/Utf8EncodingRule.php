<?php

declare(strict_types=1);

namespace RSSBridge\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports files that are not valid UTF-8. Legacy single-byte encodings such as
 * CP1251 / CP1252 survive as raw bytes inside string literals and comments,
 * where neither the parser nor PHPStan's type system notices them. Those bytes
 * corrupt feed output (invalid UTF-8 in Atom/XML, U+FFFD replacement chars in
 * JSON/HTML) and silently break string matching and CSS selectors. This rule
 * reads each analysed file and flags every invalid byte sequence, showing the
 * likely CP1251 / CP1252 decoding so the fix is obvious.
 *
 * @implements Rule<FileNode>
 */
final class Utf8EncodingRule implements Rule
{
    private const PREVIEW_LIMIT = 24;

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $contents = file_get_contents($scope->getFile());
        if ($contents === false || $contents === '') {
            return [];
        }
        if (preg_match('//u', $contents) === 1) {
            return [];
        }

        $errors = [];
        $lineNumber = 0;
        foreach (explode("\n", $contents) as $line) {
            $lineNumber++;
            if (preg_match('//u', $line) === 1) {
                continue;
            }
            $count = preg_match_all('/[\x80-\xFF]+/', $line, $matches);
            if ($count === false || $count === 0) {
                continue;
            }
            foreach ($matches[0] as $run) {
                if (preg_match('//u', $run) === 1) {
                    continue;
                }
                $errors[] = RuleErrorBuilder::message($this->describe($run))
                    ->line($lineNumber)
                    ->identifier('encoding.invalidUtf8')
                    ->build();
            }
        }

        return $errors;
    }

    private function describe(string $bytes): string
    {
        $preview = $bytes;
        if (strlen($bytes) > self::PREVIEW_LIMIT) {
            $preview = substr($bytes, 0, self::PREVIEW_LIMIT) . '...';
        }

        $hex = '';
        $length = strlen($preview);
        for ($i = 0; $i < $length; $i++) {
            $hex .= '\x' . strtoupper(bin2hex($preview[$i]));
        }

        $candidates = [];
        foreach (['CP1251' => 'Windows-1251', 'CP1252' => 'Windows-1252'] as $label => $encoding) {
            $candidates[] = $label . ': "' . mb_convert_encoding($preview, 'UTF-8', $encoding) . '"';
        }

        return 'File is not valid UTF-8: byte sequence ' . $hex
            . ' (looks like ' . implode('; ', $candidates) . '). Re-save the file as UTF-8.';
    }
}
