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
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that detects sensitive data exposure through serialization.
 *
 * Detects security-critical patterns in __toString() or jsonSerialize():
 * - json_encode($this) → Exposes entire entity
 * - serialize($this) → Exposes entire entity including private fields
 *
 * Why this is better than regex:
 * ✅ Ignores serialization in comments
 * ✅ Ignores serialization in strings
 * ✅ Only detects actual function calls with $this argument
 * ✅ No false positives
 *
 * Example:
 * ```php
 * // This comment mentions json_encode($this) ← Regex detects! Visitor ignores ✅
 * public function __toString(): string {
 *     return json_encode($this); ← Detected by visitor ✅
 * }
 * ```
 */
final class SensitiveDataExposureVisitor extends NodeVisitorAbstract
{
    /**
     * Serialization functions that expose entire object.
     */
    private const SERIALIZATION_FUNCTIONS = ['json_encode', 'serialize'];

    private bool $exposesEntireObject = false;

    public function enterNode(Node $node): ?Node
    {
        // Pattern: json_encode($this) or serialize($this)
        if ($this->isSerializationOfThis($node)) {
            $this->exposesEntireObject = true;
        }

        return null;
    }

    /**
     * Check if entire object is exposed through serialization.
     */
    public function exposesEntireObject(): bool
    {
        return $this->exposesEntireObject;
    }

    /**
     * Check if node is json_encode($this) or serialize($this).
     */
    private function isSerializationOfThis(Node $node): bool
    {
        if (!$node instanceof FuncCall) {
            return false;
        }

        // Check if it's json_encode() or serialize()
        $functionName = $this->getFunctionName($node);
        if (null === $functionName) {
            return false;
        }

        if (!in_array(strtolower($functionName), self::SERIALIZATION_FUNCTIONS, true)) {
            return false;
        }

        // Check if first argument is $this
        $firstArg = $node->args[0]->value ?? null;

        return $firstArg instanceof Variable && 'this' === $firstArg->name;
    }

    /**
     * Extract function name from FuncCall node.
     */
    private function getFunctionName(FuncCall $node): ?string
    {
        if ($node->name instanceof Name) {
            return $node->name->toString();
        }

        return null;
    }
}
