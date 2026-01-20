<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\EntityManagerClearAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for EntityManagerClearAnalyzer.
 *
 * This analyzer detects memory leak risks from batch operations without EntityManager::clear().
 * Pattern: Sequential INSERT/UPDATE/DELETE operations on the same table without clearing the identity map.
 */
final class EntityManagerClearAnalyzerTest extends TestCase
{
    private EntityManagerClearAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new EntityManagerClearAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            20,  // batchSizeThreshold: 20 operations to trigger detection
        );
    }

    #[Test]
    public function it_detects_batch_insert_operations(): void
    {
        // Arrange: 25 sequential INSERT operations on same table (above threshold)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name, price) VALUES ('Product {$i}', 19.99)", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect batch operations without clear()
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect batch INSERT operations');

        $issue = $issuesArray[0];
        self::assertStringContainsString('Memory Leak', $issue->getTitle());
        self::assertStringContainsString('25', $issue->getTitle());
        self::assertStringContainsString('products', $issue->getTitle());
    }

    #[Test]
    public function it_detects_batch_update_operations(): void
    {
        // Arrange: 22 sequential UPDATE operations
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 22; $i++) {
            $queries->addQuery("UPDATE users SET status = 'active' WHERE id = {$i}", 1.5);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect batch UPDATE operations
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('22', $issue->getTitle());
        self::assertStringContainsString('users', $issue->getTitle());
    }

    #[Test]
    public function it_detects_batch_delete_operations(): void
    {
        // Arrange: 30 sequential DELETE operations
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 30; $i++) {
            $queries->addQuery("DELETE FROM temp_data WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect batch DELETE operations
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('30', $issue->getTitle());
        self::assertStringContainsString('temp_data', $issue->getTitle());
    }

    #[Test]
    public function it_does_not_flag_operations_below_threshold(): void
    {
        // Arrange: Only 15 operations (below threshold of 20)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 15; $i++) {
            $queries->addQuery("INSERT INTO products (name, price) VALUES ('Product {$i}', 19.99)", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (below threshold)
        self::assertCount(0, $issues, 'Should not detect below threshold');
    }

    #[Test]
    public function it_groups_operations_by_table(): void
    {
        // Arrange: 25 operations on 'products', 25 on 'users' (both should be detected)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect both tables separately
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect operations on both tables');

        $titles = array_map(fn ($issue) => $issue->getTitle(), $issuesArray);
        $allTitles = implode(' ', $titles);

        self::assertStringContainsString('products', $allTitles);
        self::assertStringContainsString('users', $allTitles);
    }

    #[Test]
    public function it_requires_sequential_operations(): void
    {
        // Arrange: 25 operations but with large gaps (not sequential)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);

            // Add 15 unrelated queries between each operation (exceeds maxGap of 10)
            for ($j = 1; $j <= 15; $j++) {
                $queries->addQuery("SELECT * FROM other_table WHERE id = {$j}", 0.5);
            }
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (operations not sequential enough)
        self::assertCount(0, $issues, 'Should not detect non-sequential operations');
    }

    #[Test]
    public function it_allows_small_gaps_between_operations(): void
    {
        // Arrange: 25 operations with small gaps (within maxGap of 10)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);

            // Add 5 unrelated queries (within maxGap)
            for ($j = 1; $j <= 5; $j++) {
                $queries->addQuery("SELECT * FROM other_table WHERE id = {$j}", 0.5);
            }
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect (small gaps are allowed)
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect with small gaps');
    }

    #[Test]
    public function it_verifies_sequential_proximity(): void
    {
        // Arrange: Mix of close and far operations (70% threshold test)
        $queries = QueryDataBuilder::create();

        // 20 close operations (70% of 25 = 17.5, so this should pass)
        for ($i = 1; $i <= 20; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
            if ($i <= 20) { // @phpstan-ignore-line Always true but kept for clarity
                $queries->addQuery("SELECT 1", 0.1);  // Small gap
            }
        }

        // 5 far operations
        for ($i = 21; $i <= 25; $i++) {
            for ($j = 1; $j <= 15; $j++) {
                $queries->addQuery("SELECT * FROM other_table WHERE id = {$j}", 0.5);
            }
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect (>70% are sequential)
        $issuesArray = $issues->toArray();
        self::assertGreaterThanOrEqual(1, count($issuesArray), 'Should detect when 70%+ are sequential');
    }

    #[Test]
    public function it_provides_correct_severity(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should have warning severity (from SuggestionFactory)
        $issuesArray = $issues->toArray();
        $issue = $issuesArray[0];

        self::assertEquals('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_provides_batch_operation_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should provide suggestion with clear() guidance
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();

        self::assertNotNull($suggestion);

        $description = $suggestion->getDescription();
        self::assertStringContainsString('clear', strtolower($description));

        $code = $suggestion->getCode();
        self::assertStringContainsString('clear', strtolower($code));
    }

    #[Test]
    public function it_includes_threshold_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Description should mention threshold
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('20', $description, 'Should mention threshold value');
        self::assertStringContainsString('threshold', strtolower($description));
    }

    #[Test]
    public function it_includes_clear_method_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Description should mention EntityManager::clear()
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('EntityManager::clear()', $description);
    }

    #[Test]
    public function it_includes_backtrace_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQueryWithBacktrace(
                "INSERT INTO products (name) VALUES ('Product {$i}')",
                [['file' => 'ProductImporter.php', 'line' => 42 + $i]],
                2.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should include backtrace from first query
        $issuesArray = $issues->toArray();
        $backtrace = $issuesArray[0]->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
        self::assertEquals('ProductImporter.php', $backtrace[0]['file']);
        self::assertEquals(43, $backtrace[0]['line']);  // First iteration (i=1, 42+1)
    }

    #[Test]
    public function it_includes_query_details(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect 25 operations (even if queries are deduplicated in profiler)
        $issuesArray = $issues->toArray();
        $issue = $issuesArray[0];

        // The issue should report detecting 25 operations
        self::assertStringContainsString('25 operations', $issue->getTitle());

        // Queries array may contain deduplicated examples (1 representative query)
        // but the count is still reported correctly in the title
        self::assertGreaterThan(0, count($issue->getQueries()), 'Should include at least one representative query');
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Empty collection
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return empty collection
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_only_select_queries(): void
    {
        // Arrange: Only SELECT queries (no write operations)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 50; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (no write operations)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_extracts_table_name_correctly(): void
    {
        // Arrange: Various SQL formats
        $queries = QueryDataBuilder::create()
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("INSERT INTO my_table (col) VALUES ('val')")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 1")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 2")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 3")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 4")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 5")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 6")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 7")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 8")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 9")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 10")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 11")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 12")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 13")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 14")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 15")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 16")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 17")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 18")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 19")
            ->addQuery("UPDATE my_other_table SET col = 'val' WHERE id = 20")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 1")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 2")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 3")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 4")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 5")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 6")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 7")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 8")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 9")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 10")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 11")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 12")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 13")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 14")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 15")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 16")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 17")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 18")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 19")
            ->addQuery("DELETE FROM yet_another_table WHERE id = 20");

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should extract correct table names
        $issuesArray = $issues->toArray();
        self::assertCount(3, $issuesArray, 'Should detect all three tables');

        $titles = array_map(fn ($issue) => $issue->getTitle(), $issuesArray);
        $allTitles = implode(' ', $titles);

        self::assertStringContainsString('my_table', $allTitles);
        self::assertStringContainsString('my_other_table', $allTitles);
        self::assertStringContainsString('yet_another_table', $allTitles);
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: IssueCollection uses generator pattern
        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }

    #[Test]
    public function it_respects_custom_threshold(): void
    {
        // Arrange: Analyzer with high threshold
        $analyzer = new EntityManagerClearAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            50,  // High threshold
        );

        $queries = QueryDataBuilder::create();

        // Only 30 operations (below high threshold)
        for ($i = 1; $i <= 30; $i++) {
            $queries->addQuery("INSERT INTO products (name) VALUES ('Product {$i}')", 2.0);
        }

        // Act
        $issues = $analyzer->analyze($queries->build());

        // Assert: Should NOT detect (below custom threshold)
        self::assertCount(0, $issues, 'Should respect custom threshold');
    }

    #[Test]
    public function it_detects_real_world_import_pattern(): void
    {
        // Arrange: Realistic CSV import scenario
        $queries = QueryDataBuilder::create();

        // Simulating importing 100 products from CSV
        for ($i = 1; $i <= 100; $i++) {
            $queries->addQueryWithBacktrace(
                "INSERT INTO products (sku, name, price, stock) VALUES ('SKU{$i}', 'Product {$i}', 19.99, 100)",
                [['file' => 'CsvImporter.php', 'line' => 87]],
                2.5,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect this as a memory leak risk
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('100', $issue->getTitle());
        self::assertStringContainsString('Memory Leak', $issue->getTitle());
    }

    #[Test]
    public function it_detects_mixed_write_operations_on_same_table(): void
    {
        // Arrange: Mix of INSERT, UPDATE, DELETE on same table
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("INSERT INTO logs (message) VALUES ('Log {$i}')", 1.0);
        }

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("UPDATE logs SET processed = 1 WHERE id = {$i}", 1.0);
        }

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("DELETE FROM logs WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect 30 operations on 'logs' table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('30', $issue->getTitle());
        self::assertStringContainsString('logs', $issue->getTitle());
    }
}
