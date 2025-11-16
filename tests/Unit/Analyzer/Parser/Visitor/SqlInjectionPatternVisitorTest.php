<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser\Visitor;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SqlInjectionPatternVisitorTest extends TestCase
{
    private PhpCodeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpCodeParser();
    }

    public function testDetectsInterpolationWithCurlyBraces(): void
    {
        $method = new ReflectionMethod(TestClass::class, 'methodWithCurlyBraceInterpolation');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        $this->assertTrue($patterns['interpolation'], 'Should detect {$var} interpolation');
    }

    public function testDetectsSprintfWithGetParameter(): void
    {
        $method = new ReflectionMethod(TestClass::class, 'methodWithSprintfAndGet');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        $this->assertTrue($patterns['sprintf'], 'Should detect sprintf with $_GET');
    }
}

class TestClass
{
    private int $id = 1;

    public function methodWithCurlyBraceInterpolation(\Doctrine\DBAL\Connection $connection): void
    {
        $status = 5;
        $sql = "UPDATE products SET status = {$status} WHERE id = {$this->id}";
        $connection->executeStatement($sql);
    }

    public function methodWithSprintfAndGet(\Doctrine\DBAL\Connection $connection): void
    {
        $email = $_GET['email'] ?? '';
        $sql = sprintf("SELECT * FROM users WHERE email = '%s'", $email);
        $connection->executeQuery($sql);
    }
}
