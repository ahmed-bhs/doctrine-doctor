<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser\Visitor;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\InsecureRandomVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class InsecureRandomVisitorTest extends TestCase
{
    private const INSECURE_FUNCTIONS = ['rand', 'mt_rand', 'uniqid', 'time', 'microtime'];

    public function testDetectsDirectRandCall(): void
    {
        $code = '<?php
        function generateToken() {
            return rand();
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(1, $calls);
        $this->assertSame('direct_call', $calls[0]['type']);
        $this->assertSame('rand', $calls[0]['function']);
    }

    public function testDetectsDirectMtRandCall(): void
    {
        $code = '<?php
        function generateToken() {
            $random = mt_rand(1, 100);
            return $random;
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(1, $calls);
        $this->assertSame('mt_rand', $calls[0]['function']);
    }

    public function testDetectsUniqidCall(): void
    {
        $code = '<?php
        function generateToken() {
            return uniqid();
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(1, $calls);
        $this->assertSame('uniqid', $calls[0]['function']);
    }

    public function testDetectsWeakHashWithRand(): void
    {
        $code = '<?php
        function generateToken() {
            return md5(rand());
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(2, $calls, 'Should detect both rand() and md5(rand())');

        // AST traversal order: parent first (md5), then children (rand)
        // First detection: md5(rand()) weak hash
        $this->assertSame('weak_hash', $calls[0]['type']);
        $this->assertSame('md5', $calls[0]['function']);

        // Second detection: rand() direct call
        $this->assertSame('direct_call', $calls[1]['type']);
        $this->assertSame('rand', $calls[1]['function']);
    }

    public function testDetectsSha1WithMtRand(): void
    {
        $code = '<?php
        function generateToken() {
            return sha1(mt_rand());
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(2, $calls);
        // AST traversal: parent (sha1) first, then child (mt_rand)
        $this->assertSame('weak_hash', $calls[0]['type']);
        $this->assertSame('sha1', $calls[0]['function']);
        $this->assertSame('direct_call', $calls[1]['type']);
        $this->assertSame('mt_rand', $calls[1]['function']);
    }

    public function testIgnoresCommentsWithFunctionNames(): void
    {
        $code = '<?php
        function generateToken() {
            // Never use rand() for tokens!
            // mt_rand() is also insecure
            /* uniqid() should be avoided */
            return bin2hex(random_bytes(32));
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(0, $calls, 'Should ignore function names in comments');
    }

    public function testIgnoresStringLiteralsWithFunctionNames(): void
    {
        $code = '<?php
        function logWarning() {
            $message = "Do not use rand() or mt_rand()";
            echo $message;
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(0, $calls, 'Should ignore function names in strings');
    }

    public function testIgnoresSecureFunctions(): void
    {
        $code = '<?php
        function generateToken() {
            return bin2hex(random_bytes(32));
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(0, $calls, 'Should not flag secure functions');
    }

    public function testIgnoresRandomIntFunction(): void
    {
        $code = '<?php
        function generateNumber() {
            return random_int(1, 100);
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(0, $calls, 'random_int() is secure, should not be flagged');
    }

    public function testDetectsMultipleInsecureCalls(): void
    {
        $code = '<?php
        function badFunction() {
            $a = rand();
            $b = mt_rand();
            $c = uniqid();
            return $a + $b + $c;
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(3, $calls);
        $this->assertSame('rand', $calls[0]['function']);
        $this->assertSame('mt_rand', $calls[1]['function']);
        $this->assertSame('uniqid', $calls[2]['function']);
    }

    public function testProvidesLineNumbers(): void
    {
        $code = '<?php
        function test() {
            $a = rand(); // line 3
            $b = mt_rand(); // line 4
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(2, $calls);
        $this->assertSame(3, $calls[0]['line']);
        $this->assertSame(4, $calls[1]['line']);
    }

    public function testIgnoresMd5WithoutInsecureRandom(): void
    {
        $code = '<?php
        function hashPassword($password) {
            return md5($password); // Bad practice but not related to random
        }';

        $calls = $this->detectInsecureCalls($code);

        $this->assertCount(0, $calls, 'md5() alone is not flagged (different issue)');
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

        $visitor = new InsecureRandomVisitor(self::INSECURE_FUNCTIONS);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getInsecureCalls();
    }
}
