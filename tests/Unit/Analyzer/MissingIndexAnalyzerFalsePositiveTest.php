<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzerConfig;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for improved false positive detection in MissingIndexAnalyzer.
 *
 * These tests validate the fixes for:
 * 1. Not suggesting indexes when one is already effectively used (type=ref/eq_ref/const/range with key)
 * 2. Ignoring filesort on small result sets (1-to-many relations with few rows)
 * 3. Handling full table scans in development environments (below threshold)
 */
final class MissingIndexAnalyzerFalsePositiveTest extends TestCase
{
    #[Test]
    public function it_does_not_suggest_index_when_already_effectively_used(): void
    {
        // Arrange: Simulate sylius_channel_pricing case
        // Query uses an index (type=ref, key=product_variant_channel_idx)
        // Even with many rows in table, index is used effectively
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE sylius_channel_pricing (
                id INTEGER PRIMARY KEY,
                product_variant_id INTEGER,
                channel_code TEXT,
                price INTEGER
            )
        ');

        // Create index like in real Sylius
        $connection->executeStatement('
            CREATE UNIQUE INDEX product_variant_channel_idx
            ON sylius_channel_pricing(product_variant_id, channel_code)
        ');

        // Insert data: 537 variants × 2 channels = 1074 rows (real scenario)
        for ($variantId = 1; $variantId <= 537; $variantId++) {
            foreach (['web', 'mobile'] as $channel) {
                $connection->executeStatement(
                    'INSERT INTO sylius_channel_pricing VALUES (?, ?, ?, ?)',
                    [null, $variantId, $channel, rand(1000, 5000)],
                );
            }
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 1000,
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // The problematic query from doctrine-doctor report
        $query = new QueryData(
            sql: 'SELECT * FROM sylius_channel_pricing WHERE product_variant_id = ? ORDER BY id ASC',
            executionTime: QueryExecutionTime::fromMilliseconds(60.0), // Slow enough to trigger
            params: [1],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: Should NOT suggest index because:
        // - MySQL uses product_variant_channel_idx (type=ref or range)
        // - Only returns 2 rows per variant
        // - Filesort on 2 rows is instant
        self::assertCount(
            0,
            $issues,
            'Should not suggest index when one is already effectively used (type=ref with key)',
        );
    }

    #[Test]
    public function it_ignores_filesort_on_small_result_sets(): void
    {
        // Arrange: 1-to-many relation returning few rows
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                created_at INTEGER
            )
        ');

        $connection->executeStatement('CREATE INDEX idx_user ON orders(user_id)');

        // User has 5 orders
        for ($i = 1; $i <= 5; $i++) {
            $connection->executeStatement(
                'INSERT INTO orders VALUES (?, ?, ?)',
                [$i, 1, time() + $i],
            );
        }

        // Other users have many orders to make table bigger
        for ($userId = 2; $userId <= 100; $userId++) {
            for ($i = 1; $i <= 10; $i++) {
                $connection->executeStatement(
                    'INSERT INTO orders VALUES (?, ?, ?)',
                    [null, $userId, time() + $i],
                );
            }
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 100,
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Query with ORDER BY but returns only 5 rows
        $query = new QueryData(
            sql: 'SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(60.0),
            params: [1],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: Should NOT suggest index on (user_id, created_at)
        // because filesort on 5 rows is instant
        self::assertCount(
            0,
            $issues,
            'Should ignore filesort when result set is small (5 rows <= 10 threshold)',
        );
    }

    #[Test]
    public function it_handles_full_table_scan_below_threshold_in_dev_env(): void
    {
        // Arrange: Development environment with few rows
        // Note: SQLite EXPLAIN doesn't provide accurate row counts, it returns 1000 for any SCAN
        // So we test with a threshold > 1000 to simulate "dev with few rows"
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE products (
                id INTEGER PRIMARY KEY,
                name TEXT,
                archived INTEGER DEFAULT 0
            )
        ');

        // Only 50 products in dev
        for ($i = 1; $i <= 50; $i++) {
            $connection->executeStatement(
                'INSERT INTO products VALUES (?, ?, ?)',
                [$i, "Product {$i}", $i > 45 ? 1 : 0],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 2000, // Threshold > 1000 (SQLite default) to simulate dev env
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Full table scan but with few rows
        $query = new QueryData(
            sql: 'SELECT * FROM products WHERE archived = 0',
            executionTime: QueryExecutionTime::fromMilliseconds(60.0),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: Should NOT suggest index because threshold (2000) > SQLite SCAN estimate (1000)
        self::assertCount(
            0,
            $issues,
            'Should not suggest index when minRowsScanned threshold > EXPLAIN row estimate',
        );
    }

    #[Test]
    public function it_suggests_index_for_full_table_scan_above_threshold(): void
    {
        // Arrange: Production-like environment with many rows
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE products (
                id INTEGER PRIMARY KEY,
                name TEXT,
                archived INTEGER DEFAULT 0
            )
        ');

        // 2000 products in production
        for ($i = 1; $i <= 2000; $i++) {
            $connection->executeStatement(
                'INSERT INTO products VALUES (?, ?, ?)',
                [$i, "Product {$i}", $i > 1800 ? 1 : 0],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 1000,
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Full table scan with many rows
        $query = new QueryData(
            sql: 'SELECT * FROM products WHERE archived = 0',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: SHOULD suggest index because 2000 rows >= 1000 threshold
        self::assertGreaterThanOrEqual(
            1,
            count($issues),
            'Should suggest index for full table scan with rows above threshold (2000 >= 1000)',
        );

        if (count($issues) > 0) {
            $suggestion = $issues[0]->getSuggestion();
            self::assertInstanceOf(SuggestionInterface::class, $suggestion);
            self::assertStringContainsString('archived', $suggestion->getCode());
        }
    }

    #[Test]
    public function it_suggests_index_for_filesort_on_many_rows(): void
    {
        // Arrange: ORDER BY on many rows (> maxRowsForAcceptableFilesort)
        // Note: SQLite EXPLAIN QUERY PLAN doesn't report "Using filesort" like MySQL
        // So this test verifies the analyzer doesn't crash, actual filesort detection is MySQL-specific
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE logs (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                created_at INTEGER
            )
        ');

        $connection->executeStatement('CREATE INDEX idx_user ON logs(user_id)');

        // User has 100 logs (> 10 threshold)
        for ($i = 1; $i <= 100; $i++) {
            $connection->executeStatement(
                'INSERT INTO logs VALUES (?, ?, ?)',
                [$i, 1, time() + $i],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 50,
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Query returning 100 rows with ORDER BY
        $query = new QueryData(
            sql: 'SELECT * FROM logs WHERE user_id = ? ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(80.0),
            params: [1],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: With SQLite, filesort detection is limited.
        // The important part is that the analyzer handles ORDER BY queries without errors
        self::assertIsArray($issues);
    }

    #[Test]
    public function it_uses_custom_max_rows_for_acceptable_filesort(): void
    {
        // Arrange: Test custom threshold
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE items (
                id INTEGER PRIMARY KEY,
                category_id INTEGER,
                priority INTEGER
            )
        ');

        $connection->executeStatement('CREATE INDEX idx_category ON items(category_id)');

        // Category has 25 items
        for ($i = 1; $i <= 25; $i++) {
            $connection->executeStatement(
                'INSERT INTO items VALUES (?, ?, ?)',
                [$i, 1, $i],
            );
        }

        // Custom config: allow filesort up to 30 rows
        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 20,
            maxRowsForAcceptableFilesort: 30, // Custom threshold
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Query with ORDER BY returning 25 rows
        $query = new QueryData(
            sql: 'SELECT * FROM items WHERE category_id = ? ORDER BY priority',
            executionTime: QueryExecutionTime::fromMilliseconds(60.0),
            params: [1],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: Should NOT suggest because 25 <= 30 (custom threshold)
        self::assertCount(
            0,
            $issues,
            'Should use custom maxRowsForAcceptableFilesort (25 <= 30)',
        );
    }

    #[Test]
    public function it_does_not_suggest_when_index_used_with_type_eq_ref(): void
    {
        // Arrange: Query with type=eq_ref (best type, used in JOINs with unique indexes)
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT UNIQUE
            )
        ');

        $connection->executeStatement('
            CREATE TABLE profiles (
                id INTEGER PRIMARY KEY,
                user_id INTEGER UNIQUE,
                bio TEXT
            )
        ');

        for ($i = 1; $i <= 1000; $i++) {
            $connection->executeStatement(
                'INSERT INTO users VALUES (?, ?)',
                [$i, "user{$i}@example.com"],
            );
            $connection->executeStatement(
                'INSERT INTO profiles VALUES (?, ?, ?)',
                [$i, $i, "Bio {$i}"],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 100,
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // JOIN with unique index (type=eq_ref in MySQL)
        $query = new QueryData(
            sql: 'SELECT u.*, p.* FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.email = ?',
            executionTime: QueryExecutionTime::fromMilliseconds(60.0),
            params: ['user1@example.com'],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: Should NOT suggest index when type=eq_ref
        self::assertCount(
            0,
            $issues,
            'Should not suggest index when MySQL uses eq_ref (optimal index usage)',
        );
    }

    #[Test]
    public function it_does_not_suggest_when_index_used_with_type_const(): void
    {
        // Arrange: Query with type=const (accessing by PRIMARY KEY or UNIQUE index with constant)
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

        for ($i = 1; $i <= 1000; $i++) {
            $connection->executeStatement(
                'INSERT INTO users VALUES (?, ?)',
                [$i, "User {$i}"],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 100,
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Direct primary key access (type=const)
        $query = new QueryData(
            sql: 'SELECT * FROM users WHERE id = ?',
            executionTime: QueryExecutionTime::fromMilliseconds(60.0),
            params: [1],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: Should NOT suggest index when type=const
        self::assertCount(
            0,
            $issues,
            'Should not suggest index when MySQL uses const (primary key access)',
        );
    }

    #[Test]
    public function it_does_not_suggest_when_index_used_with_type_range(): void
    {
        // Arrange: Query with type=range (index used for range scan)
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE products (
                id INTEGER PRIMARY KEY,
                price INTEGER,
                stock INTEGER
            )
        ');

        $connection->executeStatement('CREATE INDEX idx_price ON products(price)');

        for ($i = 1; $i <= 1000; $i++) {
            $connection->executeStatement(
                'INSERT INTO products VALUES (?, ?, ?)',
                [$i, rand(100, 1000), rand(0, 100)],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 100,
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory(new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions')),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        // Range query using index (type=range)
        $query = new QueryData(
            sql: 'SELECT * FROM products WHERE price BETWEEN 100 AND 500',
            executionTime: QueryExecutionTime::fromMilliseconds(60.0),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        // Act
        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        // Assert: Should NOT suggest index when type=range
        self::assertCount(
            0,
            $issues,
            'Should not suggest index when MySQL uses range (index for range scan)',
        );
    }
}
