<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser\Visitor;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\InsecureRandomVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class InsecureRandomVisitorTest extends TestCase
{
    private const INSECURE_FUNCTIONS = ['rand', 'mt_rand', 'uniqid', 'time', 'microtime'];

    public function test_detects_direct_rand_call(): void
    {
        $code = '<?php
        function generateToken() {
            return rand();
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(1, $calls);
        self::assertSame('direct_call', $calls[0]['type']);
        self::assertSame('rand', $calls[0]['function']);
    }

    public function test_detects_direct_mt_rand_call(): void
    {
        $code = '<?php
        function generateToken() {
            $random = mt_rand(1, 100);
            return $random;
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(1, $calls);
        self::assertSame('mt_rand', $calls[0]['function']);
    }

    public function test_detects_uniqid_call(): void
    {
        $code = '<?php
        function generateToken() {
            return uniqid();
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(1, $calls);
        self::assertSame('uniqid', $calls[0]['function']);
    }

    public function test_detects_weak_hash_with_rand(): void
    {
        $code = '<?php
        function generateToken() {
            return md5(rand());
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(2, $calls, 'Should detect both rand() and md5(rand())');

        // AST traversal order: parent first (md5), then children (rand)
        // First detection: md5(rand()) weak hash
        self::assertSame('weak_hash', $calls[0]['type']);
        self::assertSame('md5', $calls[0]['function']);

        // Second detection: rand() direct call
        self::assertSame('direct_call', $calls[1]['type']);
        self::assertSame('rand', $calls[1]['function']);
    }

    public function test_detects_sha1_with_mt_rand(): void
    {
        $code = '<?php
        function generateToken() {
            return sha1(mt_rand());
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(2, $calls);
        // AST traversal: parent (sha1) first, then child (mt_rand)
        self::assertSame('weak_hash', $calls[0]['type']);
        self::assertSame('sha1', $calls[0]['function']);
        self::assertSame('direct_call', $calls[1]['type']);
        self::assertSame('mt_rand', $calls[1]['function']);
    }

    public function test_ignores_comments_with_function_names(): void
    {
        $code = '<?php
        function generateToken() {
            // Never use rand() for tokens!
            // mt_rand() is also insecure
            /* uniqid() should be avoided */
            return bin2hex(random_bytes(32));
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(0, $calls, 'Should ignore function names in comments');
    }

    public function test_ignores_string_literals_with_function_names(): void
    {
        $code = '<?php
        function logWarning() {
            $message = "Do not use rand() or mt_rand()";
            echo $message;
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(0, $calls, 'Should ignore function names in strings');
    }

    public function test_ignores_secure_functions(): void
    {
        $code = '<?php
        function generateToken() {
            return bin2hex(random_bytes(32));
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(0, $calls, 'Should not flag secure functions');
    }

    public function test_ignores_random_int_function(): void
    {
        $code = '<?php
        function generateNumber() {
            return random_int(1, 100);
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(0, $calls, 'random_int() is secure, should not be flagged');
    }

    public function test_detects_multiple_insecure_calls(): void
    {
        $code = '<?php
        function badFunction() {
            $a = rand();
            $b = mt_rand();
            $c = uniqid();
            return $a + $b + $c;
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(3, $calls);
        self::assertSame('rand', $calls[0]['function']);
        self::assertSame('mt_rand', $calls[1]['function']);
        self::assertSame('uniqid', $calls[2]['function']);
    }

    public function test_provides_line_numbers(): void
    {
        $code = '<?php
        function test() {
            $a = rand(); // line 3
            $b = mt_rand(); // line 4
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(2, $calls);
        self::assertSame(3, $calls[0]['line']);
        self::assertSame(4, $calls[1]['line']);
    }

    public function test_ignores_md5_without_insecure_random(): void
    {
        $code = '<?php
        function hashPassword($password) {
            return md5($password); // Bad practice but not related to random
        }';

        $calls = $this->detectInsecureCalls($code);

        self::assertCount(0, $calls, 'md5() alone is not flagged (different issue)');
    }

    /**
     * Helper method to detect insecure calls in PHP code.
     *
     * @return array<array{type: string, function: string, line: int}>
     */
    private function detectInsecureCalls(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        self::assertIsArray($ast, 'Parser should return an array');

        $visitor = new InsecureRandomVisitor(self::INSECURE_FUNCTIONS);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getInsecureCalls();
    }
}
