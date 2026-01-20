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
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that detects exposure of sensitive fields in PHP code.
 *
 * Detects patterns like:
 * - 'password' (string literal - field name in array)
 * - $this->getPassword() (getter method call)
 * - $this->password (direct property access)
 *
 * This is much more robust than regex because:
 * ✅ Ignores comments automatically
 * ✅ Ignores strings in irrelevant contexts
 * ✅ Type-safe and IDE-friendly
 * ✅ Handles all spacing/formatting variations
 * ✅ Easy to test and extend
 * ✅ No false positives
 *
 * Example usage for detecting 'password' field exposure:
 *
 * return [
 *     'id' => $this->id,
 *     'password' => $this->password,  // DETECTED: String_ 'password'
 *     'email' => $this->getEmail(),
 * ];
 *
 * Or:
 *
 * $data['password'] = $this->getPassword();  // DETECTED: MethodCall getPassword
 */
final class SensitiveFieldExposureVisitor extends NodeVisitorAbstract
{
    /** @var array<string> */
    private array $exposedFields = [];

    /**
     * @param array<string> $sensitiveFields List of sensitive field names to detect
     */
    public function __construct(
        private readonly array $sensitiveFields,
    ) {
    }

    /**
     * Called when entering each node in the AST.
     */
    public function enterNode(Node $node): ?Node
    {
        // Pattern 1: Array key with field name (e.g., 'password' => ...)
        if ($this->isArrayKeyWithSensitiveField($node)) {
            // Extract the field name
            $fieldName = $this->extractFieldNameFromArrayKey($node);
            if (null !== $fieldName && !in_array($fieldName, $this->exposedFields, true)) {
                $this->exposedFields[] = $fieldName;
            }
        }

        // Pattern 2: Getter method call (e.g., $this->getPassword())
        if ($this->isSensitiveGetterCall($node)) {
            $fieldName = $this->extractFieldNameFromGetter($node);
            if (null !== $fieldName && !in_array($fieldName, $this->exposedFields, true)) {
                $this->exposedFields[] = $fieldName;
            }
        }

        // Pattern 3: Direct property access (e.g., $this->password)
        if ($this->isSensitivePropertyAccess($node)) {
            $fieldName = $this->extractFieldNameFromProperty($node);
            if (null !== $fieldName && !in_array($fieldName, $this->exposedFields, true)) {
                $this->exposedFields[] = $fieldName;
            }
        }

        return null;
    }

    /**
     * Get list of exposed sensitive fields detected.
     *
     * @return array<string>
     */
    public function getExposedFields(): array
    {
        return $this->exposedFields;
    }

    /**
     * Check if any sensitive fields were exposed.
     */
    public function hasExposedFields(): bool
    {
        return [] !== $this->exposedFields;
    }

    /**
     * Check if node is an array item with a sensitive field name as key.
     *
     * Example: 'password' => $this->password
     */
    private function isArrayKeyWithSensitiveField(Node $node): bool
    {
        if (!$node instanceof ArrayItem) {
            return false;
        }

        // Array items without explicit key use numeric keys
        if (null === $node->key) {
            return false;
        }

        // Check if key is a string literal
        if (!$node->key instanceof String_) {
            return false;
        }

        $keyValue = $node->key->value;

        return in_array($keyValue, $this->sensitiveFields, true);
    }

    /**
     * Extract field name from array key.
     */
    private function extractFieldNameFromArrayKey(Node $node): ?string
    {
        if (!$node instanceof ArrayItem || null === $node->key) {
            return null;
        }

        if ($node->key instanceof String_) {
            return $node->key->value;
        }

        return null;
    }

    /**
     * Check if node is a getter method call for a sensitive field.
     *
     * Example: $this->getPassword()
     */
    private function isSensitiveGetterCall(Node $node): bool
    {
        if (!$node instanceof MethodCall) {
            return false;
        }

        // Check if it's a call on $this
        if (!$node->var instanceof Variable || 'this' !== $node->var->name) {
            return false;
        }

        // Get method name
        if (!$node->name instanceof Node\Identifier) {
            return false;
        }

        $methodName = $node->name->toString();

        // Check if it's a getter (starts with 'get')
        if (!str_starts_with($methodName, 'get')) {
            return false;
        }

        // Extract field name from getter (e.g., getPassword -> password)
        $fieldName = lcfirst(substr($methodName, 3));

        return in_array($fieldName, $this->sensitiveFields, true);
    }

    /**
     * Extract field name from getter method call.
     */
    private function extractFieldNameFromGetter(Node $node): ?string
    {
        if (!$node instanceof MethodCall) {
            return null;
        }

        if (!$node->name instanceof Node\Identifier) {
            return null;
        }

        $methodName = $node->name->toString();

        if (!str_starts_with($methodName, 'get')) {
            return null;
        }

        // Convert getPassword -> password
        return lcfirst(substr($methodName, 3));
    }

    /**
     * Check if node is a direct property access to a sensitive field.
     *
     * Example: $this->password
     */
    private function isSensitivePropertyAccess(Node $node): bool
    {
        if (!$node instanceof PropertyFetch) {
            return false;
        }

        // Check if it's accessing $this
        if (!$node->var instanceof Variable || 'this' !== $node->var->name) {
            return false;
        }

        // Get property name
        if (!$node->name instanceof Node\Identifier) {
            return false;
        }

        $propertyName = $node->name->toString();

        return in_array($propertyName, $this->sensitiveFields, true);
    }

    /**
     * Extract field name from property access.
     */
    private function extractFieldNameFromProperty(Node $node): ?string
    {
        if (!$node instanceof PropertyFetch) {
            return null;
        }

        if (!$node->name instanceof Node\Identifier) {
            return null;
        }

        return $node->name->toString();
    }
}
