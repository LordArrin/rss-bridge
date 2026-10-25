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
 * Forbids usage of legacy DOM API (DOMDocument, DOMNode, etc.)
 * and enforces migration to new Dom\HTMLDocument, Dom\Element, etc.
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
        if ($node instanceof Name) {
            return $this->processClassName($node, $scope);
        }

        if ($node instanceof PropertyFetch) {
            return $this->processPropertyFetch($node, $scope);
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

        return (string)end($parts);
    }
}
