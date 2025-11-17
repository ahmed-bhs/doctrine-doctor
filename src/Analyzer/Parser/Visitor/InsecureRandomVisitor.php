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
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that detects insecure random number generator usage.
 *
 * Detects security-critical patterns:
 * - Direct calls: rand(), mt_rand(), uniqid(), time(), microtime()
 * - Weak hashing: md5(rand()), sha1(mt_rand()), etc.
 *
 * Why this is better than regex:
 * ✅ Ignores function names in comments automatically
 * ✅ Ignores function names in strings
 * ✅ Detects nested calls: md5(rand())
 * ✅ No false positives
 * ✅ Clear, maintainable code
 *
 * Example:
 * ```php
 * // This comment mentions rand() ← Regex detects this! Visitor ignores it ✅
 * $token = bin2hex(random_bytes(32)); ← Correct, not flagged
 * $bad = md5(rand()); ← Detected by visitor ✅
 * ```
 */
final class InsecureRandomVisitor extends NodeVisitorAbstract
{
    /**
     * Weak hash functions that shouldn't be used with weak randomness.
     */
    private const WEAK_HASH_FUNCTIONS = ['md5', 'sha1'];

    /**
     * @var array<array{type: string, function: string, line: int}>
     */
    private array $insecureCalls = [];

    /**
     * @param array<string> $insecureFunctions Functions to detect (rand, mt_rand, etc.)
     */
    public function __construct(
        private readonly array $insecureFunctions,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        // Pattern 1: Direct insecure function calls (rand(), mt_rand(), etc.)
        if ($this->isInsecureFunctionCall($node) && $node instanceof FuncCall) {
            $functionName = $this->getFunctionName($node);
            if (null !== $functionName) {
                $this->insecureCalls[] = [
                    'type' => 'direct_call',
                    'function' => $functionName,
                    'line' => $node->getStartLine(),
                ];
            }
        }

        // Pattern 2: Weak hash with insecure random (md5(rand()), sha1(mt_rand()))
        if ($this->isWeakHashWithInsecureRandom($node) && $node instanceof FuncCall) {
            $functionName = $this->getFunctionName($node);
            if (null !== $functionName) {
                $this->insecureCalls[] = [
                    'type' => 'weak_hash',
                    'function' => $functionName,
                    'line' => $node->getStartLine(),
                ];
            }
        }

        return null;
    }

    /**
     * Get all detected insecure calls.
     *
     * @return array<array{type: string, function: string, line: int}>
     */
    public function getInsecureCalls(): array
    {
        return $this->insecureCalls;
    }

    /**
     * Check if node is a direct call to insecure function.
     * Examples: rand(), mt_rand(), uniqid()
     */
    private function isInsecureFunctionCall(Node $node): bool
    {
        if (!$node instanceof FuncCall) {
            return false;
        }

        $functionName = $this->getFunctionName($node);
        if (null === $functionName) {
            return false;
        }

        return in_array(strtolower($functionName), array_map('strtolower', $this->insecureFunctions), true);
    }

    /**
     * Check if node is weak hash function with insecure random as argument.
     * Examples: md5(rand()), sha1(mt_rand()), md5(time())
     */
    private function isWeakHashWithInsecureRandom(Node $node): bool
    {
        if (!$node instanceof FuncCall) {
            return false;
        }

        // Check if it's a weak hash function
        $hashFunction = $this->getFunctionName($node);
        if (null === $hashFunction) {
            return false;
        }

        if (!in_array(strtolower($hashFunction), self::WEAK_HASH_FUNCTIONS, true)) {
            return false;
        }

        // Check if first argument is an insecure random function
        $firstArg = $node->args[0]->value ?? null;
        if (!$firstArg instanceof FuncCall) {
            return false;
        }

        $randomFunction = $this->getFunctionName($firstArg);
        if (null === $randomFunction) {
            return false;
        }

        return in_array(strtolower($randomFunction), array_map('strtolower', $this->insecureFunctions), true);
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
