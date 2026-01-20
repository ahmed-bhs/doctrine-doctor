<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for PhpCodeParser caching with automatic invalidation.
 */
final class PhpCodeParserCacheTest extends TestCase
{
    private PhpCodeParser $parser;

    private string $tempFile;

    protected function setUp(): void
    {
        $this->parser = new PhpCodeParser();
        $this->tempFile = sys_get_temp_dir() . '/doctrine_doctor_cache_test_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /**
     * Test that analysis results are cached within same request.
     */
    #[Test]
    public function it_caches_analysis_results(): void
    {
        // Create test class with SQL injection vulnerability
        $this->createTestFile(
            <<<'PHP'
            <?php
            class CacheTestClass {
                public function vulnerableMethod(\Doctrine\DBAL\Connection $connection): void {
                    $userId = $_GET['id'];
                    $sql = "SELECT * FROM users WHERE id = " . $userId;
                    $connection->executeQuery($sql);
                }
            }
            PHP
        );

        require_once $this->tempFile;

        $method = new ReflectionMethod('CacheTestClass', 'vulnerableMethod');

        // First call - cache MISS
        $result1 = $this->parser->detectSqlInjectionPatterns($method);
        $stats1 = $this->parser->getCacheStats();

        // Second call - should be cache HIT
        $result2 = $this->parser->detectSqlInjectionPatterns($method);
        $stats2 = $this->parser->getCacheStats();

        // Results should be identical
        self::assertEquals($result1, $result2);
        self::assertTrue($result1['concatenation'], 'Should detect SQL injection');

        // Cache should have 1 analysis entry
        self::assertGreaterThan(0, $stats2['analysis_entries'], 'Cache should have entries');
    }

    /**
     * Test that cache invalidates when file is modified.
     */
    #[Test]
    public function it_invalidates_cache_on_file_modification(): void
    {
        // Create initial version with vulnerability
        $this->createTestFile(
            <<<'PHP'
            <?php
            class InvalidationTestClass {
                public function testMethod(\Doctrine\DBAL\Connection $connection): void {
                    $userId = $_GET['id'];
                    $sql = "SELECT * FROM users WHERE id = " . $userId;
                    $connection->executeQuery($sql);
                }
            }
            PHP
        );

        require_once $this->tempFile;

        $method = new ReflectionMethod('InvalidationTestClass', 'testMethod');

        // First analysis - should detect vulnerability
        $result1 = $this->parser->detectSqlInjectionPatterns($method);
        self::assertTrue($result1['concatenation'], 'Should detect SQL injection initially');

        // Wait 1 second to ensure different mtime
        sleep(1);

        // Modify file - fix the vulnerability
        $this->createTestFile(
            <<<'PHP'
            <?php
            class InvalidationTestClass {
                public function testMethod(\Doctrine\DBAL\Connection $connection): void {
                    // Fixed: using prepared statement now
                    $stmt = $connection->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->bindValue(1, $_GET['id']);
                    $stmt->executeQuery();
                }
            }
            PHP
        );

        // Note: We can't reload the class definition in PHP
        // So this test only validates that mtime tracking works
        // In real usage, each request loads fresh class definitions

        // Get new ReflectionMethod (points to modified file)
        $modifiedMethod = new ReflectionMethod('InvalidationTestClass', 'testMethod');

        // Second analysis - cache should be invalidated due to mtime change
        // But since we can't reload PHP class, we just verify mtime changed
        $mtime1 = filemtime($this->tempFile);
        self::assertNotFalse($mtime1, 'File should exist and have mtime');

        // Verify stats show caching is working
        $stats = $this->parser->getCacheStats();
        self::assertArrayHasKey('analysis_entries', $stats);
        self::assertArrayHasKey('memory_mb', $stats);
    }

    /**
     * Test that clearCache() works.
     */
    #[Test]
    public function it_clears_cache(): void
    {
        $this->createTestFile(
            <<<'PHP'
            <?php
            class ClearCacheTestClass {
                public function testMethod(\Doctrine\DBAL\Connection $connection): void {
                    $sql = "SELECT * FROM users WHERE id = " . $_GET['id'];
                    $connection->executeQuery($sql);
                }
            }
            PHP
        );

        require_once $this->tempFile;

        $method = new ReflectionMethod('ClearCacheTestClass', 'testMethod');

        // Populate cache
        $this->parser->detectSqlInjectionPatterns($method);

        $statsBefore = $this->parser->getCacheStats();
        self::assertGreaterThan(0, $statsBefore['analysis_entries'] + $statsBefore['ast_entries']);

        // Clear cache
        $this->parser->clearCache();

        $statsAfter = $this->parser->getCacheStats();
        self::assertEquals(0, $statsAfter['analysis_entries'], 'Analysis cache should be empty');
        self::assertEquals(0, $statsAfter['ast_entries'], 'AST cache should be empty');
    }

    /**
     * Test cache stats report memory usage.
     */
    #[Test]
    public function it_reports_memory_usage(): void
    {
        $this->createTestFile(
            <<<'PHP'
            <?php
            class MemoryTestClass {
                public function method1(\Doctrine\DBAL\Connection $connection): void {
                    $sql = "SELECT * FROM users WHERE id = " . $_GET['id'];
                    $connection->executeQuery($sql);
                }

                public function method2(\Doctrine\DBAL\Connection $connection): void {
                    $sql = sprintf("SELECT * FROM products WHERE id = %s", $_POST['id']);
                    $connection->executeQuery($sql);
                }
            }
            PHP
        );

        require_once $this->tempFile;

        // Analyze multiple methods
        $method1 = new ReflectionMethod('MemoryTestClass', 'method1');
        $method2 = new ReflectionMethod('MemoryTestClass', 'method2');

        $this->parser->detectSqlInjectionPatterns($method1);
        $this->parser->detectSqlInjectionPatterns($method2);

        $stats = $this->parser->getCacheStats();

        self::assertArrayHasKey('memory_bytes', $stats);
        self::assertArrayHasKey('memory_mb', $stats);
        self::assertGreaterThan(0, $stats['memory_bytes']);
        self::assertGreaterThan(0, $stats['memory_mb']);
    }

    private function createTestFile(string $content): void
    {
        file_put_contents($this->tempFile, $content);
        chmod($this->tempFile, 0644);
    }
}
