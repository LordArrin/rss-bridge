<?php

declare(strict_types=1);

namespace RSSBridge\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * Flags comparisons of Dom\Element::$tagName against literals containing lowercase letters.
 *
 * In PHP 8.4+ \Dom\HTMLDocument, tagName is UPPERCASE ('DIV', 'H2') per the WHATWG DOM spec,
 * so `$el->tagName === 'div'` is always false. Use $node->localName (always lowercase).
 *
 * Covers: ===, ==, !==, !=, in_array() needles, match() arms.
 * Does NOT track variable indirection ($t = $el->tagName; $t === 'div') — use the grep pass.
 *
 * @implements Rule<Node>
 */
final class DomTagNameComparisonRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof BinaryOp) {
            return $this->processBinaryOp($node, $scope);
        }
        if ($node instanceof FuncCall) {
            return $this->processInArray($node, $scope);
        }
        if ($node instanceof Match_) {
            return $this->processMatch($node, $scope);
        }
        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function processBinaryOp(BinaryOp $node, Scope $scope): array
    {
        if (
            $node instanceof BinaryOp\Identical === false
            && $node instanceof BinaryOp\Equal === false
            && $node instanceof BinaryOp\NotIdentical === false
            && $node instanceof BinaryOp\NotEqual === false
        ) {
            return [];
        }

        foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$fetch, $literal]) {
            if (
                $fetch instanceof PropertyFetch
                && $literal instanceof String_
                && $this->isDomTagNameFetch($fetch, $scope) === true
                && $this->hasLowercase($literal->value) === true
            ) {
                return [$this->error($literal->value)];
            }
        }
        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function processInArray(FuncCall $node, Scope $scope): array
    {
        if ($node->name instanceof Name === false || strtolower($node->name->toString()) !== 'in_array') {
            return [];
        }
        $args = $node->getArgs();
        if (count($args) < 2) {
            return [];
        }
        $needle = $args[0]->value;
        $haystack = $args[1]->value;
        if ($needle instanceof PropertyFetch === false || $this->isDomTagNameFetch($needle, $scope) === false) {
            return [];
        }
        if ($haystack instanceof Array_ === false) {
            return [];
        }
        foreach ($haystack->items as $item) {
            if ($item->value instanceof String_ && $this->hasLowercase($item->value->value) === true) {
                return [$this->error($item->value->value)];
            }
        }
        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function processMatch(Match_ $node, Scope $scope): array
    {
        if ($node->cond instanceof PropertyFetch === false || $this->isDomTagNameFetch($node->cond, $scope) === false) {
            return [];
        }
        foreach ($node->arms as $arm) {
            if ($arm->conds === null) {
                continue;
            }
            foreach ($arm->conds as $cond) {
                if ($cond instanceof String_ && $this->hasLowercase($cond->value) === true) {
                    return [$this->error($cond->value)];
                }
            }
        }
        return [];
    }

    private function isDomTagNameFetch(PropertyFetch $node, Scope $scope): bool
    {
        if ($node->name instanceof Identifier === false || $node->name->toString() !== 'tagName') {
            return false;
        }

        // Strip null so nullable Dom\Element (?Dom\Element) is still recognised.
        $varType = TypeCombinator::removeNull($scope->getType($node->var));
        $element = new ObjectType('Dom\Element');
        $domNode = new ObjectType('Dom\Node');

        // Both directions: exact/subclass (element is supertype of var) and
        // supertype/mixed (var is supertype of element). Avoids the inverted check.
        return $element->isSuperTypeOf($varType)->yes() === true
            || $domNode->isSuperTypeOf($varType)->yes() === true
            || $varType->isSuperTypeOf($element)->yes() === true;
    }

    private function hasLowercase(string $value): bool
    {
        // 'div', 'h2', 'Div' -> true; 'DIV', '123', '' -> false.
        return $value !== '' && $value !== strtoupper($value);
    }

    private function error(string $literal): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Comparing tagName to "%s" will always fail for HTML documents: in PHP 8.4+ \Dom\HTMLDocument tagName is UPPERCASE ("%s"). Use $node->localName (always lowercase) instead.',
            $literal,
            strtoupper($literal)
        ))
            ->identifier('dom.tagNameLowercaseComparison')
            ->tip('Replace ->tagName with ->localName, or wrap with strtolower(). See https://wiki.php.net/rfc/domdocument_html5_parser')
            ->build();
    }
}
