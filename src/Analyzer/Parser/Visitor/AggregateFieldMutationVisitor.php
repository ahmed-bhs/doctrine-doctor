<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitorAbstract;

final class AggregateFieldMutationVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $mutatedNumericFields = [];

    /** @var list<string> */
    private array $accessedCollections = [];

    /**
     * @param list<string> $numericFields
     * @param list<string> $collectionFields
     */
    public function __construct(
        private readonly array $numericFields,
        private readonly array $collectionFields,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        $this->detectNumericFieldMutation($node);
        $this->detectCollectionAccess($node);

        return null;
    }

    /**
     * @return list<string>
     */
    public function getMutatedNumericFields(): array
    {
        return array_values(array_unique($this->mutatedNumericFields));
    }

    /**
     * @return list<string>
     */
    public function getAccessedCollections(): array
    {
        return array_values(array_unique($this->accessedCollections));
    }

    public function hasBothMutations(): bool
    {
        return [] !== $this->mutatedNumericFields && [] !== $this->accessedCollections;
    }

    private function detectNumericFieldMutation(Node $node): void
    {
        if ($node instanceof AssignOp && $this->isThisPropertyFetch($node->var)) {
            $fieldName = $this->getPropertyName($node->var);

            if (null !== $fieldName && \in_array($fieldName, $this->numericFields, true)) {
                $this->mutatedNumericFields[] = $fieldName;
            }

            return;
        }

        if ($node instanceof Assign && $this->isThisPropertyFetch($node->var)) {
            $fieldName = $this->getPropertyName($node->var);

            if (null !== $fieldName && \in_array($fieldName, $this->numericFields, true)) {
                $this->mutatedNumericFields[] = $fieldName;
            }
        }
    }

    private function detectCollectionAccess(Node $node): void
    {
        if ($node instanceof Assign && $node->var instanceof ArrayDimFetch) {
            $arrayVar = $node->var->var;

            if ($this->isThisPropertyFetch($arrayVar)) {
                $fieldName = $this->getPropertyName($arrayVar);

                if (null !== $fieldName && \in_array($fieldName, $this->collectionFields, true)) {
                    $this->accessedCollections[] = $fieldName;
                }
            }

            return;
        }

        if ($node instanceof MethodCall && $this->isThisPropertyFetch($node->var)) {
            $fieldName = $this->getPropertyName($node->var);

            if (null === $fieldName || !\in_array($fieldName, $this->collectionFields, true)) {
                return;
            }

            if ($node->name instanceof Identifier && $this->isCollectionMutationMethod($node->name->toString())) {
                $this->accessedCollections[] = $fieldName;
            }
        }
    }

    private function isThisPropertyFetch(Node $node): bool
    {
        return $node instanceof PropertyFetch
            && $node->var instanceof Variable
            && 'this' === $node->var->name
            && $node->name instanceof Identifier;
    }

    private function getPropertyName(Node $node): ?string
    {
        if ($node instanceof PropertyFetch && $node->name instanceof Identifier) {
            return $node->name->toString();
        }

        return null;
    }

    private function isCollectionMutationMethod(string $methodName): bool
    {
        return \in_array($methodName, ['add', 'removeElement', 'remove', 'clear', 'set'], true);
    }
}
