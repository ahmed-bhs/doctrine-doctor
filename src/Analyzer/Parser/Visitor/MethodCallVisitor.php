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
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that detects method call patterns in PHP AST.
 *
 * Detects patterns like:
 * - $this->initializeTranslationsCollection()
 * - $this->initMethod()
 * - Supports wildcards: $this->init*Collection()
 *
 * Used to detect Sylius-style initialization where traits define
 * constructor aliases:
 *
 * use TranslatableTrait {
 *     __construct as private initializeTranslationsCollection;
 * }
 *
 * public function __construct() {
 *     $this->initializeTranslationsCollection(); // <- Detected here
 * }
 *
 * Example AST structure for: $this->initMethod();
 *
 * MethodCall (
 *   var: Variable ($this)
 *   name: Identifier (initMethod)
 * )
 */
final class MethodCallVisitor extends NodeVisitorAbstract
{
    private bool $hasMethodCall = false;

    public function __construct(
        private readonly string $methodNamePattern,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        // Detect: $this->methodName()
        if ($node instanceof MethodCall
            && $node->var instanceof Variable
            && 'this' === $node->var->name
        ) {
            // Only handle static method names (Identifier), skip dynamic calls
            if ($node->name instanceof Node\Identifier) {
                $methodName = $node->name->toString();
                if ($this->matchesPattern($methodName)) {
                    $this->hasMethodCall = true;
                }
            }
        }

        return null;
    }

    public function hasMethodCall(): bool
    {
        return $this->hasMethodCall;
    }

    /**
     * Check if method name matches the pattern.
     *
     * Supports wildcards:
     * - Pattern "initialize*Collection" matches "initializeTranslationsCollection"
     * - Pattern "init*" matches "initializeAll", "initItems", etc.
     */
    private function matchesPattern(string $methodName): bool
    {
        // Exact match
        if ($methodName === $this->methodNamePattern) {
            return true;
        }

        // Wildcard support
        if (str_contains($this->methodNamePattern, '*')) {
            // Replace wildcards with regex wildcard BEFORE preg_quote
            // Otherwise preg_quote escapes * to \* and str_replace won't find it
            $pattern = str_replace('*', '__WILDCARD__', $this->methodNamePattern);
            $pattern = preg_quote($pattern, '/');
            $pattern = str_replace('__WILDCARD__', '.*', $pattern);
            return (bool) preg_match('/^' . $pattern . '$/', $methodName);
        }

        return false;
    }
}
