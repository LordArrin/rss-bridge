<?php

declare(strict_types=1);

namespace RSSBridge\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids usage of the legacy DOM API (DOMDocument, DOMNode, ...) and enforces
 * migration to the new \Dom\* API (\Dom\HTMLDocument, \Dom\Element, ...).
 *
 * IMPORTANT: PHPStan does not dispatch every AST node to Rule<Node> rules.
 * In particular the Name child of `new X`, static calls and `instanceof` is
 * never visited on its own, so checking only Name nodes misses the most common
 * legacy patterns. This rule therefore also inspects the parent expressions
 * and statements and extracts class names from them directly.
 *
 * @implements Rule<Node>
 */
final class ForbidOldDomApiRule implements Rule
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_CLASSES = [
        'DOMDocument',
        'DOMDocumentFragment',
        'DOMDocumentType',
        'DOMNode',
        'DOMNodeList',
        'DOMElement',
        'DOMAttr',
        'DOMText',
        'DOMComment',
        'DOMCdataSection',
        'DOMCharacterData',
        'DOMXPath',
        'DOMNamedNodeMap',
        'DOMNameSpaceNode',
        'DOMEntity',
        'DOMEntityReference',
        'DOMNotation',
        'DOMProcessingInstruction',
        'DOMImplementation',
    ];

    /**
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'DOMDocument' => '\Dom\HTMLDocument::createFromString() or \Dom\XMLDocument::createFromString()',
        'DOMDocumentFragment' => '\Dom\DocumentFragment',
        'DOMNode' => '\Dom\Node',
        'DOMNodeList' => '\Dom\NodeList',
        'DOMElement' => '\Dom\Element',
        'DOMAttr' => '\Dom\Attr',
        'DOMText' => '\Dom\Text',
        'DOMComment' => '\Dom\Comment',
        'DOMCdataSection' => '\Dom\CdataSection',
        'DOMCharacterData' => '\Dom\CharacterData',
        'DOMXPath' => '\Dom\XPath',
    ];

    /**
     * Instance properties deprecated in PHP 8.4, mapped to the classes they belong to.
     *
     * @var array<string, list<string>>
     */
    private const DEPRECATED_PROPERTIES = [
        'actualEncoding' => ['DOMDocument', 'DOMEntity'],
        'config' => ['DOMDocument'],
        'encoding' => ['DOMEntity'],
        'version' => ['DOMEntity'],
    ];

    /**
     * Dedup guard: a class name can reach this rule both as a standalone Name
     * node (type hints) and via its parent expression. Report each combination
     * of file, line and class name only once per analysis run.
     *
     * @var array<string, true>
     */
    private static array $reported = [];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @param Node $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        if ($node instanceof Name) {
            $errors = array_merge($errors, $this->processClassName($node, $scope));
        }

        foreach ($this->extractClassNames($node) as $className) {
            $errors = array_merge($errors, $this->processClassName($className, $scope));
        }

        if ($node instanceof PropertyFetch) {
            $errors = array_merge($errors, $this->processPropertyFetch($node, $scope));
        }

        return $errors;
    }

    /**
     * Collects class-name nodes carried by parent expressions/statements whose
     * Name children are not dispatched to rules individually.
     *
     * @return list<Name>
     */
    private function extractClassNames(Node $node): array
    {
        $names = [];

        if (
            $node instanceof Node\Expr\New_
            || $node instanceof Node\Expr\Instanceof_
            || $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\StaticPropertyFetch
            || $node instanceof Node\Expr\ClassConstFetch
        ) {
            if ($node->class instanceof Name) {
                $names[] = $node->class;
            }

            return $names;
        }

        if ($node instanceof Node\Stmt\Catch_) {
            foreach ($node->types as $type) {
                $names[] = $type;
            }

            return $names;
        }

        if ($node instanceof Node\Stmt\Class_) {
            if ($node->extends instanceof Name) {
                $names[] = $node->extends;
            }
            foreach ($node->implements as $interface) {
                $names[] = $interface;
            }

            return $names;
        }

        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $names[] = $interface;
            }

            return $names;
        }

        if ($node instanceof Node\Param) {
            return $this->extractNamesFromType($node->type);
        }

        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            return $this->extractNamesFromType($node->returnType);
        }

        if ($node instanceof Node\Stmt\Property) {
            return $this->extractNamesFromType($node->type);
        }

        return $names;
    }

    /**
     * @return list<Name>
     */
    private function extractNamesFromType(?Node $type): array
    {
        if ($type instanceof Name) {
            return [$type];
        }

        if ($type instanceof Node\NullableType) {
            return $this->extractNamesFromType($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];
            foreach ($type->types as $subType) {
                $names = array_merge($names, $this->extractNamesFromType($subType));
            }

            return $names;
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function processClassName(Name $node, Scope $scope): array
    {
        $className = $this->resolveClassName($node, $scope);
        $shortName = $this->getShortClassName($className);

        if (in_array($shortName, self::FORBIDDEN_CLASSES, true) === false) {
            return [];
        }

        $dedupKey = $scope->getFile() . ':' . $node->getStartLine() . ':' . $className;
        if (isset(self::$reported[$dedupKey]) === true) {
            return [];
        }
        self::$reported[$dedupKey] = true;

        $replacement = self::REPLACEMENTS[$shortName] ?? 'the new \Dom\* API';

        return [
            RuleErrorBuilder::message(sprintf(
                'Legacy DOM API "%s" is forbidden. Use: %s',
                $className,
                $replacement
            ))
                ->identifier('dom.oldApiForbidden')
                ->build(),
        ];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function processPropertyFetch(PropertyFetch $node, Scope $scope): array
    {
        if ($node->name instanceof Identifier === false) {
            return [];
        }

        $propertyName = $node->name->toString();

        if (isset(self::DEPRECATED_PROPERTIES[$propertyName]) === false) {
            return [];
        }

        $type = $scope->getType($node->var);

        if ($type->isObject()->yes() === false) {
            return [];
        }

        $owner = null;
        foreach ($type->getObjectClassNames() as $className) {
            if (in_array($this->getShortClassName($className), self::DEPRECATED_PROPERTIES[$propertyName], true) === true) {
                $owner = $className;
                break;
            }
        }

        if ($owner === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Property %s::$%s is deprecated since PHP 8.4 and will be removed in PHP 9.0. Use the \Dom\* API instead',
                $owner,
                $propertyName
            ))
                ->identifier('dom.deprecatedProperty')
                ->build(),
        ];
    }

    private function resolveClassName(Name $node, Scope $scope): string
    {
        if ($node instanceof Name\FullyQualified) {
            return $node->toString();
        }

        return $scope->resolveName($node);
    }

    private function getShortClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }
}
