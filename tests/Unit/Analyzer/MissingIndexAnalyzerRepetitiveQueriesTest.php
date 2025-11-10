<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Config\MissingIndexAnalyzerConfig;
use AhmedBhs\DoctrineDoctor\Analyzer\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test that MissingIndexAnalyzer detects repetitive queries even when they're VERY fast.
 *
 * Critical: This is the exact scenario from /demo/showcase where queries are
 * 0.27ms (< 50ms threshold) but repeated 11 times.
 */
final class MissingIndexAnalyzerRepetitiveQueriesTest extends TestCase
{
    #[Test]
    public function it_analyzes_very_fast_repetitive_queries(): void
    {
        // Arrange: Simulate showcase scenario
        // 11 queries × 0.27ms = very fast individually, but repetitive pattern
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT,
                email TEXT
            )
        ');

        // Insert data to trigger EXPLAIN
        for ($i = 1; $i <= 100; $i++) {
            $connection->executeStatement(
                'INSERT INTO users VALUES (?, ?, ?)',
                [$i, "User {$i}", "user{$i}@example.com"],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,  // IMPORTANT: 50ms threshold
            minRowsScanned: 5,
        );

        $analyzer = new MissingIndexAnalyzer(
            templateRenderer: new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions'),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Create 11 identical queries with 0.27ms execution time
        // These are BELOW the 50ms threshold, but should still be analyzed because repetitive
        $queries = [];
        for ($i = 1; $i <= 11; $i++) {
            $queries[] = new QueryData(
                sql: 'SELECT * FROM users WHERE id = ?',
                executionTime: QueryExecutionTime::fromMilliseconds(0.27), // < 50ms!
                params: [$i],
                backtrace: [['file' => __FILE__, 'line' => __LINE__]],
            );
        }

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray($queries)));

        // Assert: Should detect the repetitive pattern even though queries are fast
        // Because: $isRepetitive = count >= 3 (line 133 MissingIndexAnalyzer.php)
        self::assertGreaterThanOrEqual(1, count($issues), 'Should detect repetitive fast queries (11 queries of 0.27ms each)');

        if (count($issues) > 0) {
            $issue = $issues[0];
            self::assertStringContainsString('Index', $issue->getTitle());
            self::assertNotNull($issue->getSuggestion());
        }
    }

    #[Test]
    public function it_ignores_single_fast_query(): void
    {
        // Arrange
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT
            )
        ');

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 5,
        );

        $analyzer = new MissingIndexAnalyzer(
            templateRenderer: new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions'),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Single fast query (not repetitive)
        $query = new QueryData(
            sql: 'SELECT * FROM users WHERE id = ?',
            executionTime: QueryExecutionTime::fromMilliseconds(0.27), // < 50ms
            params: [1],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: Should NOT analyze a single fast query
        self::assertCount(0, $issues, 'Should ignore single fast query (0.27ms < 50ms threshold, not repetitive)');
    }

    #[Test]
    public function it_analyzes_with_threshold_3_repetitions(): void
    {
        // Arrange: Test the exact threshold (3 repetitions)
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT
            )
        ');

        for ($i = 1; $i <= 50; $i++) {
            $connection->executeStatement(
                'INSERT INTO users VALUES (?, ?)',
                [$i, "user{$i}@example.com"],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 100, // High threshold to force repetitive detection
            minRowsScanned: 5,
        );

        $analyzer = new MissingIndexAnalyzer(
            templateRenderer: new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions'),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Exactly 3 repetitions (threshold)
        $queries = [];
        for ($i = 1; $i <= 3; $i++) {
            $queries[] = new QueryData(
                sql: 'SELECT * FROM users WHERE email = ?',
                executionTime: QueryExecutionTime::fromMilliseconds(1.0), // Very fast
                params: ["user{$i}@example.com"],
                backtrace: [['file' => __FILE__, 'line' => __LINE__]],
            );
        }

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray($queries)));

        // Assert: 3 repetitions should trigger analysis ($isRepetitive = count >= 3)
        self::assertGreaterThanOrEqual(1, count($issues), 'Should analyze queries repeated exactly 3 times');
    }

    #[Test]
    public function it_does_not_analyze_2_repetitions(): void
    {
        // Arrange: Below threshold (2 repetitions)
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('CREATE TABLE users (id INTEGER, email TEXT)');

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 100,
            minRowsScanned: 5,
        );

        $analyzer = new MissingIndexAnalyzer(
            templateRenderer: new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions'),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Only 2 repetitions (below threshold of 3)
        $queries = [];
        for ($i = 1; $i <= 2; $i++) {
            $queries[] = new QueryData(
                sql: 'SELECT * FROM users WHERE email = ?',
                executionTime: QueryExecutionTime::fromMilliseconds(1.0),
                params: ["user{$i}@example.com"],
                backtrace: [['file' => __FILE__, 'line' => __LINE__]],
            );
        }

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray($queries)));

        // Assert: Should NOT analyze (< 3 repetitions AND fast)
        self::assertCount(0, $issues, 'Should not analyze queries repeated only 2 times (threshold is 3)');
    }
}
