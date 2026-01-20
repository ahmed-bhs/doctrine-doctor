<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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

    public function test_detects_interpolation_with_curly_braces(): void
    {
        $method = new ReflectionMethod(TestClass::class, 'methodWithCurlyBraceInterpolation');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertTrue($patterns['interpolation'], 'Should detect {$var} interpolation');
    }

    public function test_detects_sprintf_with_get_parameter(): void
    {
        $method = new ReflectionMethod(TestClass::class, 'methodWithSprintfAndGet');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertTrue($patterns['sprintf'], 'Should detect sprintf with $_GET');
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
