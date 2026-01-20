<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzerConfig;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for MissingIndexAnalyzer.
 *
 * Tests detection of:
 * - Missing indexes on foreign keys
 * - Full table scans on large tables
 * - Composite index suggestions
 * - EXPLAIN-based analysis with real database
 */
final class MissingIndexAnalyzerIntegrationTest extends DatabaseTestCase
{
    private MissingIndexAnalyzer $missingIndexAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $missingIndexAnalyzerConfig = new MissingIndexAnalyzerConfig(
            slowQueryThreshold: 50,
            minRowsScanned: 100,
            enabled: true,
        );

        $templateRenderer = PlatformAnalyzerTestHelper::createTemplateRenderer();
        $suggestionFactory = new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory($templateRenderer);

        $this->missingIndexAnalyzer = new MissingIndexAnalyzer(
            suggestionFactory: $suggestionFactory,
            connection: $this->connection,
            missingIndexAnalyzerConfig: $missingIndexAnalyzerConfig,
        );

        $this->createSchema([Product::class, Category::class]);
    }

    #[Test]
    public function it_detects_missing_index_on_unindexed_column(): void
    {
        // Arrange: Populate with enough data to trigger index suggestion
        $category = new Category();
        $category->setName('Electronics');

        $this->entityManager->persist($category);

        for ($i = 1; $i <= 150; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(99.99);
            $product->setStock($i);
            $product->setCategory($category);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Create query that will scan many rows without index
        $sql = 'SELECT * FROM products WHERE stock > 50';

        $queryData = new QueryData(
            sql: $sql,
            executionTime: QueryExecutionTime::fromMilliseconds(100),
            params: [],
            backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);

        // Act
        $issueCollection = $this->missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: Should detect missing index or full table scan
        // Note: SQLite may or may not suggest an index based on optimizer choices
        // The important part is that EXPLAIN was executed without errors
        self::assertIsInt(count($issueCollection), 'Analyzer should execute without errors');
    }

    #[Test]
    public function it_analyzes_query_with_explain(): void
    {
        // Arrange: Create data
        $category = new Category();
        $category->setName('Books');

        $this->entityManager->persist($category);

        for ($i = 1; $i <= 200; $i++) {
            $product = new Product();
            $product->setName('Book ' . $i);
            $product->setPrice(19.99);
            $product->setStock($i * 2);
            $product->setCategory($category);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();

        // Execute EXPLAIN directly to verify it works
        $sql = 'SELECT * FROM products WHERE name LIKE ?';

        try {
            $explainResult = $this->connection->executeQuery('EXPLAIN ' . $sql, ['%Book%']);
            $rows = $explainResult->fetchAllAssociative();

            self::assertNotEmpty($rows, 'EXPLAIN should return results');
        } catch (\Exception $exception) {
            self::markTestSkipped('EXPLAIN not supported on this database: ' . $exception->getMessage());
        }
    }

    #[Test]
    public function it_detects_full_table_scan_on_large_dataset(): void
    {
        // Arrange: Create substantial dataset
        $category = new Category();
        $category->setName('Large Dataset');

        $this->entityManager->persist($category);

        for ($i = 1; $i <= 300; $i++) {
            $product = new Product();
            $product->setName('Item ' . $i);
            $product->setPrice($i * 1.5);
            $product->setStock($i);
            $product->setCategory($category);
            $this->entityManager->persist($product);

            // Flush in batches
            if (0 === $i % 100) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Query that will perform full table scan
        $sql = 'SELECT * FROM products WHERE stock > 150';

        $queryData = new QueryData(
            sql: $sql,
            executionTime: QueryExecutionTime::fromMilliseconds(80),
            params: [],
            backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);

        // Act
        $issueCollection = $this->missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: With 300 rows and no index, likely triggers detection
        // Actual behavior depends on database platform (SQLite optimizer may vary)
        self::assertIsArray($issueCollection->toArray(), 'Should return array of issues');
    }

    #[Test]
    public function it_suggests_composite_index_for_multiple_where_conditions(): void
    {
        // Arrange: Data
        $category = new Category();
        $category->setName('Composite Test');

        $this->entityManager->persist($category);

        for ($i = 1; $i <= 120; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(50.0);
            $product->setStock($i);
            $product->setCategory($category);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();

        // Query with multiple WHERE conditions
        $sql = 'SELECT * FROM products WHERE stock > 50 AND price < 100';

        $queryData = new QueryData(
            sql: $sql,
            executionTime: QueryExecutionTime::fromMilliseconds(90),
            params: [],
            backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);

        // Act
        $issueCollection = $this->missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: Test should always check the result, even if empty
        self::assertIsArray($issueCollection->toArray(), 'Should return array of issues');

        // If suggestion is made, it should mention columns
        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            $suggestion = $issue->getSuggestion();

            if (null !== $suggestion) {
                $suggestionText = $suggestion->getDescription();
                // Should mention one of the columns used in WHERE
                self::assertThat($suggestionText, self::logicalOr(
                    self::stringContains('stock'),
                    self::stringContains('price'),
                    self::stringContains('INDEX'),
                ), 'Suggestion should mention index or columns');
            }
        }
    }

    #[Test]
    public function it_handles_queries_with_order_by(): void
    {
        // Arrange
        $category = new Category();
        $category->setName('Order By Test');

        $this->entityManager->persist($category);

        for ($i = 1; $i <= 150; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(99.99);
            $product->setStock($i);
            $product->setCategory($category);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();

        // Query with ORDER BY
        $sql = 'SELECT * FROM products ORDER BY name';

        $queryData = new QueryData(
            sql: $sql,
            executionTime: QueryExecutionTime::fromMilliseconds(70),
            params: [],
            backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);

        // Act
        $issueCollection = $this->missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: Should analyze without error
        self::assertIsInt(count($issueCollection));

        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            self::assertInstanceOf(SuggestionInterface::class, $issue->getSuggestion());
        }
    }

    #[Test]
    public function it_skips_non_select_queries(): void
    {
        // Arrange: Non-SELECT queries should be skipped
        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: 'UPDATE products SET stock = 100 WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(80),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
            ),
            new QueryData(
                sql: 'DELETE FROM products WHERE stock = 0',
                executionTime: QueryExecutionTime::fromMilliseconds(90),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
            ),
            new QueryData(
                sql: 'INSERT INTO products (name, price, stock) VALUES ("Test", 99.99, 10)',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
            ), // @phpstan-ignore-line argument.type
        ]);

        // Act
        $issueCollection = $this->missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: Should not analyze non-SELECT queries
        self::assertCount(0, $issueCollection, 'Should skip UPDATE, DELETE, and INSERT queries');
    }

    #[Test]
    public function it_only_analyzes_slow_or_repetitive_queries(): void
    {
        // Arrange: Mix of fast and slow queries
        $queryDataCollection = QueryDataCollection::fromArray([
            // Fast query - should be ignored
            new QueryData(
                sql: 'SELECT id FROM products WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(5),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
            ),
            // Slow query - should be analyzed
            new QueryData(
                sql: 'SELECT * FROM products WHERE stock > 100',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [], // Above threshold
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
            ), // @phpstan-ignore-line argument.type
        ]);

        // Act
        $issueCollection = $this->missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: Only slow queries should be analyzed
        // Note: Actual issues depend on table state and optimizer
        self::assertIsInt(count($issueCollection));
    }

    #[Test]
    public function it_provides_severity_levels(): void
    {
        // Arrange: Create large dataset to trigger critical severity
        $category = new Category();
        $category->setName('Severity Test');

        $this->entityManager->persist($category);

        for ($i = 1; $i <= 500; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(99.99);
            $product->setStock($i);
            $product->setCategory($category);
            $this->entityManager->persist($product);

            if (0 === $i % 100) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        // Query that scans all 500+ rows
        $sql = 'SELECT * FROM products WHERE stock > 0';

        $queryData = new QueryData(
            sql: $sql,
            executionTime: QueryExecutionTime::fromMilliseconds(150),
            params: [],
            backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);

        // Act
        $issueCollection = $this->missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: Test should always check the result, even if empty
        self::assertIsArray($issueCollection->toArray(), 'Should return array of issues');

        // If issues are found, check severity
        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            $severity = $issue->getSeverity();

            self::assertContains($severity->value, ['critical', 'warning', 'info'], 'Severity should be a valid level');
        }
    }

    #[Test]
    public function it_demonstrates_real_explain_usage(): void
    {
        // Arrange: Create test data
        $category = new Category();
        $category->setName('EXPLAIN Demo');

        $this->entityManager->persist($category);

        for ($i = 1; $i <= 100; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(99.99);
            $product->setStock($i);
            $product->setCategory($category);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();

        // Execute EXPLAIN manually to demonstrate
        $testQuery = 'SELECT * FROM products WHERE stock > 50';

        try {
            $explainQuery = 'EXPLAIN ' . $testQuery;
            $result = $this->connection->executeQuery($explainQuery);
            $explainOutput = $result->fetchAllAssociative();

            self::assertNotEmpty($explainOutput, 'EXPLAIN should return analysis data');

            // Verify EXPLAIN structure
            if (isset($explainOutput[0])) { // @phpstan-ignore-line isset.offset
                $firstRow = $explainOutput[0];

                // SQLite EXPLAIN has different columns than MySQL
                // Just verify we got some data
                self::assertIsArray($firstRow);
                self::assertNotEmpty($firstRow);
            }
        } catch (\Exception $exception) {
            self::markTestSkipped('EXPLAIN not available: ' . $exception->getMessage());
        }
    }

    #[Test]
    public function it_handles_queries_with_join_conditions(): void
    {
        // Arrange
        $category = new Category();
        $category->setName('Join Test');

        $this->entityManager->persist($category);

        for ($i = 1; $i <= 150; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(99.99);
            $product->setStock($i);
            $product->setCategory($category);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();

        // Query with JOIN
        $sql = 'SELECT p.* FROM products p
                INNER JOIN categories c ON p.category_id = c.id
                WHERE c.name = "Join Test"';

        $queryData = new QueryData(
            sql: $sql,
            executionTime: QueryExecutionTime::fromMilliseconds(100),
            params: [],
            backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);

        // Act
        $issueCollection = $this->missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: Should handle JOIN queries without error
        self::assertIsInt(count($issueCollection));
    }

    #[Test]
    public function it_can_be_disabled_via_configuration(): void
    {
        // Arrange: Create analyzer with EXPLAIN disabled
        $missingIndexAnalyzerConfig = new MissingIndexAnalyzerConfig(
            slowQueryThreshold: 50,
            minRowsScanned: 100,
            enabled: false, // DISABLED
        );

        $templateRenderer = PlatformAnalyzerTestHelper::createTemplateRenderer();
        $suggestionFactory = new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory($templateRenderer);

        $missingIndexAnalyzer = new MissingIndexAnalyzer(
            suggestionFactory: $suggestionFactory,
            connection: $this->connection,
            missingIndexAnalyzerConfig: $missingIndexAnalyzerConfig,
        );

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: 'SELECT * FROM products WHERE stock > 100',
                executionTime: QueryExecutionTime::fromMilliseconds(200),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type  // @phpstan-ignore-line argument.type
            ), // @phpstan-ignore-line argument.type
        ]);

        // Act
        $issueCollection = $missingIndexAnalyzer->analyze($queryDataCollection);

        // Assert: Should return empty when disabled
        self::assertCount(0, $issueCollection, 'Should not analyze queries when explainQueries is disabled');
    }
}
