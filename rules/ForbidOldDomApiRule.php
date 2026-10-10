<?php

declare(strict_types=1);

namespace RSSBridge\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids usage of legacy DOM API (\DOMDocument, \DOMNode, etc.)
 * and enforces migration to new \Dom\HTMLDocument, \Dom\Element, etc.
 * 
 * @implements Rule<Name>
 */
final class ForbidOldDomApiRule implements Rule
{
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

    private const REPLACEMENTS = [
        'DOMDocument' => '\\Dom\\HTMLDocument::createFromString() or \\Dom\\XMLDocument::createFromString()',
        'DOMXPath' => '\\Dom\\XPath',
        'DOMNode' => '\\Dom\\Node',
        'DOMNodeList' => '\\Dom\\NodeList',
        'DOMElement' => '\\Dom\\Element',
        'DOMAttr' => '\\Dom\\Attr',
        'DOMText' => '\\Dom\\Text',
        'DOMComment' => '\\Dom\\Comment',
        'DOMCdataSection' => '\\Dom\\CdataSection',
        'DOMDocumentFragment' => '\\Dom\\DocumentFragment',
    ];

    public function getNodeType(): string
    {
        return Name::class;
    }

    /**
     * @param Name $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $className = $this->resolveClassName($node, $scope);
        
        if ($className === null) {
            return [];
        }
        
        $shortName = $this->getShortClassName($className);
        
        if (in_array($shortName, self::FORBIDDEN_CLASSES, true) === false) {
            return [];
        }
        
        $replacement = self::REPLACEMENTS[$shortName] ?? 'new \\Dom\\* API';
        
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

    private function resolveClassName(Name $node, Scope $scope): ?string
    {
        if ($node instanceof Node\Name\FullyQualified) {
            return $node->toString();
        }
        
        $resolved = $scope->resolveName($node);
        
        if ($resolved === null) {
            return null;
        }
        
        return $resolved;
    }

    private function getShortClassName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}