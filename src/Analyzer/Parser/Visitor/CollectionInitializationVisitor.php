<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that detects collection initialization patterns in PHP AST.
 *
 * Detects patterns like:
 * - $this->fieldName = new ArrayCollection()
 * - $this->fieldName = new \Doctrine\Common\Collections\ArrayCollection()
 * - $this->fieldName = []
 *
 * This is 10x more robust than regex because:
 * ✅ Ignores comments automatically
 * ✅ Ignores strings automatically
 * ✅ Type-safe and IDE-friendly
 * ✅ Handles all spacing/formatting variations
 * ✅ Easy to test
 * ✅ Clear error messages
 *
 * Example AST structure for: $this->items = new ArrayCollection();
 *
 * Assign (
 *   var: PropertyFetch (
 *     var: Variable ($this)
 *     name: Identifier (items)
 *   )
 *   expr: New_ (
 *     class: Name (ArrayCollection)
 *   )
 * )
 */
final class CollectionInitializationVisitor extends NodeVisitorAbstract
{
    /**
     * Collection class names that we consider valid.
     */
    private const COLLECTION_CLASSES = [
        'ArrayCollection',
        'Collection',
        'Doctrine\Common\Collections\ArrayCollection',
        'Doctrine\Common\Collections\Collection',
    ];

    private bool $hasInitialization = false;

    public function __construct(
        private readonly string $fieldName,
    ) {
    }

    /**
     * Called when entering each node in the AST.
     */
    public function enterNode(Node $node): ?Node
    {
        // Pattern 1: $this->field = new ArrayCollection()
        if ($this->isNewCollectionAssignment($node)) {
            $this->hasInitialization = true;
        }

        // Pattern 2: $this->field = []
        if ($this->isArrayAssignment($node)) {
            $this->hasInitialization = true;
        }

        return null;
    }

    public function hasInitialization(): bool
    {
        return $this->hasInitialization;
    }

    /**
     * Check if node is: $this->fieldName = new ArrayCollection()
     */
    private function isNewCollectionAssignment(Node $node): bool
    {
        if (!$node instanceof Assign) {
            return false;
        }

        // Check left side: $this->fieldName
        if (!$this->isThisPropertyAccess($node->var)) {
            return false;
        }

        // Check right side: new ArrayCollection()
        if (!$node->expr instanceof New_) {
            return false;
        }

        $className = $this->getClassName($node->expr->class);

        return $this->isCollectionClass($className);
    }

    /**
     * Check if node is: $this->fieldName = []
     */
    private function isArrayAssignment(Node $node): bool
    {
        if (!$node instanceof Assign) {
            return false;
        }

        // Check left side: $this->fieldName
        if (!$this->isThisPropertyAccess($node->var)) {
            return false;
        }

        // Check right side: []
        return $node->expr instanceof Array_ && 0 === count($node->expr->items);
    }

    /**
     * Check if expression is $this->fieldName (our target field).
     */
    private function isThisPropertyAccess(Node $node): bool
    {
        if (!$node instanceof PropertyFetch) {
            return false;
        }

        // Check $this
        if (!$node->var instanceof Variable || 'this' !== $node->var->name) {
            return false;
        }

        // Check field name matches
        // Only handle static property names (Identifier), skip dynamic accesses
        if (!$node->name instanceof Node\Identifier) {
            return false;
        }

        return $node->name->toString() === $this->fieldName;
    }

    /**
     * Extract class name from New_ node.
     */
    private function getClassName(Node $classNode): string
    {
        if ($classNode instanceof Name) {
            return $classNode->toString();
        }

        return '';
    }

    /**
     * Check if className is a known collection class.
     */
    private function isCollectionClass(string $className): bool
    {
        // Normalize class name (remove leading backslash)
        $normalizedName = ltrim($className, '\\');

        foreach (self::COLLECTION_CLASSES as $collectionClass) {
            if ($normalizedName === $collectionClass) {
                return true;
            }

            // Check short name (ArrayCollection matches Doctrine\...\ArrayCollection)
            $lastBackslash = strrchr($collectionClass, '\\');
            $shortName = false !== $lastBackslash ? substr($lastBackslash, 1) : $collectionClass;
            if ($normalizedName === $shortName) {
                return true;
            }
        }

        return false;
    }
}
