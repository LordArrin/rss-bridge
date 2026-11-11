<?php

declare(strict_types=1);

namespace RSSBridge\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Detects incorrect lowercase string comparisons with the `tagName` property.
 * 
 * In PHP 8.4+ \Dom\HTMLDocument, the `tagName` property returns the tag name 
 * in UPPERCASE (e.g., 'DIV', 'A') for HTML documents, following the WHATWG DOM spec.
 * Comparing it to a lowercase string literal (e.g., $el->tagName === 'div') 
 * will always evaluate to false, causing silent logic failures.
 * 
 * @implements Rule<Node\Expr\BinaryOp>
 */
final class DomTagNameComparisonRule implements Rule
{
    public function getNodeType(): string
    {
        return BinaryOp::class;
    }

    /**
     * @param BinaryOp $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // We only care about equality/inequality comparisons
        if (
            !$node instanceof BinaryOp\Identical &&
            !$node instanceof BinaryOp\Equal &&
            !$node instanceof BinaryOp\NotIdentical &&
            !$node instanceof BinaryOp\NotEqual
        ) {
            return [];
        }

        $left = $node->left;
        $right = $node->right;

        // Find which side is the PropertyFetch and which is the String literal
        $propertyFetch = null;
        $stringLiteral = null;

        if ($left instanceof PropertyFetch && $right instanceof String_) {
            $propertyFetch = $left;
            $stringLiteral = $right;
        } elseif ($right instanceof PropertyFetch && $left instanceof String_) {
            $propertyFetch = $right;
            $stringLiteral = $left;
        } else {
            return [];
        }

        // Check if the property being accessed is 'tagName'
        if (!$propertyFetch->name instanceof Identifier || $propertyFetch->name->toString() !== 'tagName') {
            return [];
        }

        // Check if the string literal contains lowercase letters (the actual bug)
        // If it's already uppercase (e.g., 'DIV'), it's technically correct, though localName is still preferred.
        if (strtolower($stringLiteral->value) === $stringLiteral->value && $stringLiteral->value !== strtolower($stringLiteral->value)) {
            // Wait, the above logic is flawed. Let's do a proper lowercase check:
        }
        
        $isLowercaseString = (strtolower($stringLiteral->value) === $stringLiteral->value) && 
                             (strtoupper($stringLiteral->value) !== $stringLiteral->value);

        if (!$isLowercaseString) {
            return [];
        }

        // Verify that the variable is actually a DOM Node/Element to avoid false positives
        $varType = $scope->getType($propertyFetch->var);
        $isDomType = $varType->isObject()->yes() && (
            $varType->isSuperTypeOf(new ObjectType('Dom\\Node'))->yes() ||
            $varType->isSuperTypeOf(new ObjectType('Dom\\Element'))->yes() ||
            $varType->isSuperTypeOf(new ObjectType('Dom\\HTMLDocument'))->yes()
        );

        if (!$isDomType) {
            return [];
        }

        $operator = $node instanceof BinaryOp\Identical || $node instanceof BinaryOp\NotIdentical ? '===' : '==';
        $suggestedFix = $node instanceof BinaryOp\NotIdentical || $node instanceof BinaryOp\NotEqual ? '!== ' : '=== ';

        return [
            RuleErrorBuilder::message(sprintf(
                'Comparing `tagName` to a lowercase string ("%s") will always fail in PHP 8.4+ HTML documents, as `tagName` returns UPPERCASE (e.g., "%s"). ' .
                'Use `$node->localName %s"%s"` for case-insensitive lowercase comparison, or `strtolower($node->tagName) %s"%s"`.',
                $stringLiteral->value,
                strtoupper($stringLiteral->value),
                $suggestedFix,
                strtolower($stringLiteral->value),
                $suggestedFix,
                strtolower($stringLiteral->value)
            ))
                ->identifier('dom.tagNameLowercaseComparison')
                ->tip('See: https://wiki.php.net/rfc/domdocument_html5_parser and WHATWG DOM specification for Element.tagName')
                ->build(),
        ];
    }
}
