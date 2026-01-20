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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Security-focused tests for SQL Injection detection.
 * Tests OWASP Top 10 injection patterns and false positive prevention.
 */
final class SqlInjectionSecurityTest extends TestCase
{
    private PhpCodeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpCodeParser();
    }

    /**
     * Test that concatenation with user input is detected.
     */
    #[Test]
    public function it_detects_concatenation_with_user_input(): void
    {
        $method = new ReflectionMethod(VulnerableSqlTestClass::class, 'concatenationWithGet');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertTrue($patterns['concatenation'], 'Failed to detect concatenation with $_GET');
    }

    /**
     * Test that interpolation with POST is detected.
     */
    #[Test]
    public function it_detects_interpolation_with_post(): void
    {
        $method = new ReflectionMethod(VulnerableSqlTestClass::class, 'interpolationWithPost');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertTrue($patterns['interpolation'], 'Failed to detect interpolation with $_POST');
    }

    /**
     * Test that sprintf with $_GET is detected.
     */
    #[Test]
    public function it_detects_sprintf_with_get(): void
    {
        $method = new ReflectionMethod(VulnerableSqlTestClass::class, 'sprintfWithGet');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertTrue($patterns['sprintf'], 'Failed to detect sprintf with $_GET');
    }

    /**
     * Test that prepared statements are NOT flagged.
     */
    #[Test]
    public function it_does_not_flag_prepared_statements(): void
    {
        $method = new ReflectionMethod(SafeSqlTestClass::class, 'preparedStatementWithPlaceholder');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertFalse(
            $patterns['concatenation'] || $patterns['interpolation'] || $patterns['sprintf'],
            'False positive for prepared statement',
        );
    }

    /**
     * Test that query builder with parameters is NOT flagged.
     */
    #[Test]
    public function it_does_not_flag_query_builder_with_parameters(): void
    {
        $method = new ReflectionMethod(SafeSqlTestClass::class, 'queryBuilderWithParameter');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertFalse(
            $patterns['concatenation'] || $patterns['interpolation'] || $patterns['sprintf'],
            'False positive for query builder',
        );
    }

    /**
     * Test that static SQL without variables is NOT flagged.
     */
    #[Test]
    public function it_does_not_flag_static_sql(): void
    {
        $method = new ReflectionMethod(SafeSqlTestClass::class, 'staticSqlNoVariables');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertFalse(
            $patterns['concatenation'] || $patterns['interpolation'] || $patterns['sprintf'],
            'False positive for static SQL',
        );
    }

    /**
     * Test that comments containing SQL code are NOT flagged.
     */
    #[Test]
    public function it_ignores_sql_in_comments(): void
    {
        $method = new ReflectionMethod(SafeSqlTestClass::class, 'sqlInCommentsIsIgnored');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertFalse($patterns['concatenation'], 'Detected SQL in comments (false positive)');
    }

    /**
     * Test nested concatenation is detected.
     */
    #[Test]
    public function it_detects_multi_level_concatenation(): void
    {
        $method = new ReflectionMethod(VulnerableSqlTestClass::class, 'multiLevelConcatenation');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertTrue($patterns['concatenation'], 'Failed to detect multi-level concatenation');
    }

    /**
     * Test that heredoc/nowdoc with interpolation is detected.
     */
    #[Test]
    public function it_detects_heredoc_with_interpolation(): void
    {
        $method = new ReflectionMethod(VulnerableSqlTestClass::class, 'heredocWithInterpolation');
        $patterns = $this->parser->detectSqlInjectionPatterns($method);

        self::assertTrue($patterns['interpolation'], 'Failed to detect heredoc interpolation');
    }
}

/**
 * Test class with vulnerable SQL injection patterns.
 */
class VulnerableSqlTestClass
{
    public function concatenationWithGet(\Doctrine\DBAL\Connection $connection): void
    {
        $userId = $_GET['id'] ?? '1';
        $sql = "SELECT * FROM users WHERE id = " . $userId;
        $connection->executeQuery($sql);
    }

    public function interpolationWithPost(\Doctrine\DBAL\Connection $connection): void
    {
        $username = $_POST['username'] ?? 'admin';
        $sql = "SELECT * FROM users WHERE username = '{$username}'";
        $connection->executeQuery($sql);
    }

    public function sprintfWithGet(\Doctrine\DBAL\Connection $connection): void
    {
        $email = $_GET['email'] ?? 'test@example.com';
        $sql = sprintf("SELECT * FROM users WHERE email = '%s'", $email);
        $connection->executeQuery($sql);
    }

    public function multiLevelConcatenation(\Doctrine\DBAL\Connection $connection): void
    {
        $userId = $_GET['id'] ?? '1';
        $part1 = "SELECT * FROM";
        $part2 = $part1 . " users WHERE id = " . $userId;
        $sql = $part2 . " AND active = 1";
        $connection->executeQuery($sql);
    }

    public function heredocWithInterpolation(\Doctrine\DBAL\Connection $connection): void
    {
        $id = 123;
        $sql = <<<SQL
            SELECT * FROM users
            WHERE id = {$id}
        SQL;
        $connection->executeQuery($sql);
    }
}

/**
 * Test class with safe SQL patterns.
 */
class SafeSqlTestClass
{
    public function preparedStatementWithPlaceholder(\Doctrine\DBAL\Connection $connection): void
    {
        $stmt = $connection->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->bindValue(1, $_GET['id']);
        $stmt->executeQuery();
    }

    public function queryBuilderWithParameter(\Doctrine\DBAL\Connection $connection): void
    {
        $qb = $connection->createQueryBuilder();
        $qb->select('u')
           ->from('users', 'u')
           ->where('u.id = :id')
           ->setParameter('id', $_GET['id']);
    }

    public function staticSqlNoVariables(\Doctrine\DBAL\Connection $connection): void
    {
        $sql = "SELECT * FROM users WHERE id = 123";
        $connection->executeQuery($sql);
    }

    /**
     * Example of BAD code (DO NOT DO THIS):
     * $sql = "SELECT * FROM users WHERE id = " . $_GET['id'];
     */
    public function sqlInCommentsIsIgnored(\Doctrine\DBAL\Connection $connection): void
    {
        // This is a safe query
        $stmt = $connection->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->bindValue(1, 123);
    }
}
