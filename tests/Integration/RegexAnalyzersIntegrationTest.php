<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for analyzers that use regex patterns.
 * Ensures regex patterns work correctly with real-world data.
 */
final class RegexAnalyzersIntegrationTest extends TestCase
{
    // NOTE: DQLValidationAnalyzer test removed - analyzer class was deleted as it cannot work at runtime

    #[Test]
    public function missing_index_analyzer_handles_special_characters_in_sql(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('SQLite extension not available');
        }

        $connection = PlatformAnalyzerTestHelper::createSQLiteConnection();
        $templateRenderer = PlatformAnalyzerTestHelper::createTemplateRenderer();

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new SuggestionFactory($templateRenderer), // @phpstan-ignore-line argument.type
            connection: $connection,
        );

        // Test with various special characters
        $queryDataCollection = QueryDataCollection::fromArray([
            // String with escaped quotes
            new QueryData(
                sql: "SELECT * FROM users WHERE name = 'O\\'Brien'",
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [],
            ),
            // String with backslashes (Windows path)
            new QueryData(
                sql: "SELECT * FROM files WHERE path = 'C:\\\\Users\\\\test'",
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [],
            ),
            // Complex query with mixed quotes
            new QueryData(
                sql: 'SELECT * FROM products WHERE description LIKE "%test\'s product%"',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [],
            ),
        ]);

        // Should not throw regex compilation errors
        $issues = $analyzer->analyze($queryDataCollection);

        // The analyzer should execute without errors
        self::assertIsIterable($issues);
    }

    #[Test]
    public function n_plus_one_analyzer_normalizes_queries_with_special_characters(): void
    {
        if (!class_exists(\Doctrine\ORM\EntityManager::class)) {
            self::markTestSkipped('Doctrine ORM not available');
        }

        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();
        $issueFactory = PlatformAnalyzerTestHelper::createIssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();

        $analyzer = new NPlusOneAnalyzer(
            entityManager: $entityManager,
            issueFactory: $issueFactory,
            suggestionFactory: $suggestionFactory,
            threshold: 2,
        );

        // Test with queries containing special characters
        $queryDataCollection = QueryDataCollection::fromArray([
            // Same query pattern with different string values
            new QueryData(
                sql: "SELECT * FROM products WHERE name = 'Product 1'",
                executionTime: QueryExecutionTime::fromMilliseconds(10),
                params: [],
                backtrace: [],
            ),
            new QueryData(
                sql: "SELECT * FROM products WHERE name = 'Product 2'",
                executionTime: QueryExecutionTime::fromMilliseconds(10),
                params: [],
                backtrace: [],
            ),
            new QueryData(
                sql: "SELECT * FROM products WHERE name = 'Product 3'",
                executionTime: QueryExecutionTime::fromMilliseconds(10),
                params: [],
                backtrace: [],
            ),
            // With escaped quotes
            new QueryData(
                sql: "SELECT * FROM users WHERE name = 'O\\'Brien'",
                executionTime: QueryExecutionTime::fromMilliseconds(10),
                params: [],
                backtrace: [],
            ),
            new QueryData(
                sql: "SELECT * FROM users WHERE name = 'O\\'Connor'",
                executionTime: QueryExecutionTime::fromMilliseconds(10),
                params: [],
                backtrace: [],
            ),
            new QueryData(
                sql: "SELECT * FROM users WHERE name = 'O\\'Malley'",
                executionTime: QueryExecutionTime::fromMilliseconds(10),
                params: [],
                backtrace: [],
            ),
        ]);

        // Should not throw regex compilation errors
        $issues = $analyzer->analyze($queryDataCollection);

        // The analyzer should execute without errors and detect N+1
        self::assertIsIterable($issues);

        // Should detect at least one N+1 pattern (both query patterns repeat 3 times)
        $issueCount = iterator_count($issues);
        self::assertGreaterThanOrEqual(1, $issueCount, 'Should detect N+1 patterns');
    }

    #[Test]
    public function analyzers_handle_queries_with_in_clauses(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('SQLite extension not available');
        }

        $connection = PlatformAnalyzerTestHelper::createSQLiteConnection();
        $templateRenderer = PlatformAnalyzerTestHelper::createTemplateRenderer();

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new SuggestionFactory($templateRenderer), // @phpstan-ignore-line argument.type
            connection: $connection,
        );

        $queryDataCollection = QueryDataCollection::fromArray([
            // IN clause with values
            new QueryData(
                sql: 'SELECT * FROM users WHERE id IN (1, 2, 3, 4, 5)',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [],
            ),
            // IN clause with placeholder
            new QueryData(
                sql: 'SELECT * FROM products WHERE category_id IN (?)',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [[1, 2, 3]],
                backtrace: [],
            ),
        ]);

        // Should not throw regex compilation errors
        $issues = $analyzer->analyze($queryDataCollection);

        self::assertIsIterable($issues);
    }

    // NOTE: DQLValidationAnalyzer test removed - analyzer class was deleted as it cannot work at runtime

    #[Test]
    public function analyzers_handle_queries_with_numeric_literals(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('SQLite extension not available');
        }

        $connection = PlatformAnalyzerTestHelper::createSQLiteConnection();
        $templateRenderer = PlatformAnalyzerTestHelper::createTemplateRenderer();

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new SuggestionFactory($templateRenderer), // @phpstan-ignore-line argument.type
            connection: $connection,
        );

        $queryDataCollection = QueryDataCollection::fromArray([
            // Various numeric formats
            new QueryData(
                sql: 'SELECT * FROM products WHERE price > 99.99 AND stock < 100',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [],
            ),
            new QueryData(
                sql: 'SELECT * FROM orders WHERE total = 1234567890',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [],
            ),
        ]);

        // Should not throw regex compilation errors
        $issues = $analyzer->analyze($queryDataCollection);

        self::assertIsIterable($issues);
    }

    #[Test]
    public function analyzers_handle_unicode_characters(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('SQLite extension not available');
        }

        $connection = PlatformAnalyzerTestHelper::createSQLiteConnection();
        $templateRenderer = PlatformAnalyzerTestHelper::createTemplateRenderer();

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new SuggestionFactory($templateRenderer), // @phpstan-ignore-line argument.type
            connection: $connection,
        );

        $queryDataCollection = QueryDataCollection::fromArray([
            // Unicode characters
            new QueryData(
                sql: "SELECT * FROM users WHERE name = 'José García'",
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [],
            ),
            new QueryData(
                sql: "SELECT * FROM products WHERE description LIKE '%café%'",
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [],
            ),
        ]);

        // Should not throw regex compilation errors
        $issues = $analyzer->analyze($queryDataCollection);

        self::assertIsIterable($issues);
    }
}
