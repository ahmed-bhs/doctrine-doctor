<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\QueryCachingOpportunityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for QueryCachingOpportunityAnalyzer.
 *
 * This analyzer detects two types of caching opportunities:
 * 1. Frequent queries - queries executed 3+ times in the same request
 * 2. Static table queries - queries on rarely-changing lookup tables
 */
final class QueryCachingOpportunityAnalyzerTest extends TestCase
{
    private QueryCachingOpportunityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new QueryCachingOpportunityAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_frequent_query_at_info_threshold(): void
    {
        // Arrange: Same query executed 3 times (minimum threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect as INFO severity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect frequent query');
        self::assertStringContainsString('3 Times', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_detects_frequent_query_at_warning_threshold(): void
    {
        // Arrange: Same query executed 5 times (warning threshold)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect as WARNING severity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('5 Times', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_detects_frequent_query_at_critical_threshold(): void
    {
        // Arrange: Same query executed 10 times (critical threshold)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect as CRITICAL severity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('10 Times', $issuesArray[0]->getTitle());
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_does_not_detect_queries_below_threshold(): void
    {
        // Arrange: Same query executed only 2 times (below threshold of 3)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect (below threshold)
        self::assertCount(0, $issues, 'Should not detect queries below threshold');
    }

    #[Test]
    public function it_normalizes_queries_with_different_values(): void
    {
        // Arrange: Same query structure with different values
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE id = 1", 10.0)
            ->addQuery("SELECT * FROM users WHERE id = 2", 10.0)
            ->addQuery("SELECT * FROM users WHERE id = 3", 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should treat as same query pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should normalize and detect pattern');
    }

    #[Test]
    public function it_normalizes_queries_with_string_literals(): void
    {
        // Arrange: Same query with different string literals
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE name = 'Alice'", 10.0)
            ->addQuery("SELECT * FROM users WHERE name = 'Bob'", 10.0)
            ->addQuery("SELECT * FROM users WHERE name = 'Charlie'", 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should treat as same query pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
    }

    #[Test]
    public function it_normalizes_queries_with_in_clauses(): void
    {
        // Arrange: Same query with different IN clause values
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE id IN (1, 2, 3)", 10.0)
            ->addQuery("SELECT * FROM users WHERE id IN (4, 5, 6)", 10.0)
            ->addQuery("SELECT * FROM users WHERE id IN (7, 8, 9)", 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should treat as same query pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
    }

    #[Test]
    public function it_detects_static_table_countries(): void
    {
        // Arrange: Query on 'countries' table (static table)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM countries WHERE code = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table caching opportunity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect static table');
        self::assertStringContainsString('countries', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_static_table_currencies(): void
    {
        // Arrange: Query on 'currencies' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM currencies WHERE code = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('currencies', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_static_table_languages(): void
    {
        // Arrange: Query on 'languages' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM languages ORDER BY name', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('languages', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_static_table_with_join(): void
    {
        // Arrange: Query joining static table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users JOIN countries ON users.country_id = countries.id', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table in JOIN
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('countries', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_reports_static_table_only_once(): void
    {
        // Arrange: Multiple queries on same static table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM countries WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM countries WHERE id = 2', 10.0)
            ->addQuery('SELECT * FROM countries WHERE id = 3', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should report static table only once + frequent query once = 2 issues
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Static table reported once, frequent query once');
    }

    #[Test]
    public function it_detects_both_frequent_and_static_opportunities(): void
    {
        // Arrange: Mix of frequent queries and static table queries
        $queries = QueryDataBuilder::create();

        // Frequent query on regular table
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery("SELECT * FROM orders WHERE id = {$i}", 10.0);
        }

        // Query on static table
        $queries->addQuery('SELECT * FROM countries WHERE code = ?', 10.0);

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect both types
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect both frequent and static opportunities');
    }

    #[Test]
    public function it_calculates_total_time(): void
    {
        // Arrange: Same query executed 3 times with different execution times
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 15.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 20.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should calculate total time (45ms)
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('45', $description, 'Should show total time');
    }

    #[Test]
    public function it_mentions_use_result_cache_in_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should suggest useResultCache()
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('useResultCache', $description);
    }

    #[Test]
    public function it_provides_cache_duration_hint_for_static_tables(): void
    {
        // Arrange: Query on static table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM countries', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should mention cache duration
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('hour', strtolower($description));
    }

    #[Test]
    public function it_includes_backtrace(): void
    {
        // Arrange: Query with backtrace
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQueryWithBacktrace(
                "SELECT * FROM users WHERE id = {$i}",
                [['file' => 'UserRepository.php', 'line' => 42]],
                10.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should include backtrace
        $issuesArray = $issues->toArray();
        $backtrace = $issuesArray[0]->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
        self::assertEquals('UserRepository.php', $backtrace[0]['file']);
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
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }

    #[Test]
    public function it_provides_suggestion_for_frequent_queries(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertNotEmpty($suggestion->getCode(), 'Should have code in suggestion');
    }

    #[Test]
    public function it_provides_suggestion_for_static_tables(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM countries', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertNotEmpty($suggestion->getCode(), 'Should have code in suggestion');
    }

    #[Test]
    public function it_distinguishes_different_query_patterns(): void
    {
        // Arrange: Two different query patterns, each executed 3 times
        $queries = QueryDataBuilder::create();

        // Pattern 1: users table
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Pattern 2: products table
        for ($i = 1; $i <= 3; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect both patterns separately
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect each pattern separately');
    }

    #[Test]
    public function it_detects_settings_table_as_static(): void
    {
        // Arrange: Query on 'settings' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM settings WHERE key = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('settings', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_roles_table_as_static(): void
    {
        // Arrange: Query on 'roles' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM roles', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('roles', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_detects_categories_table_as_static(): void
    {
        // Arrange: Query on 'categories' table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM categories', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect static table
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('categories', strtolower($issuesArray[0]->getTitle()));
    }

    #[Test]
    public function it_does_not_suggest_caching_for_insert_queries(): void
    {
        // Arrange: Same INSERT query executed 6 times
        // This is a valid use case - inserting multiple orders
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery('INSERT INTO orders (status, total, created_at, user_id, customer_id) VALUES (?, ?, ?, ?, ?)', 0.67);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT suggest caching for INSERT queries
        // INSERT queries cannot be cached - they modify data
        self::assertCount(0, $issues, 'Should not suggest caching for INSERT queries');
    }

    #[Test]
    public function it_does_not_suggest_caching_for_update_queries(): void
    {
        // Arrange: Same UPDATE query executed 5 times
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery('UPDATE users SET status = ? WHERE id = ?', 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT suggest caching for UPDATE queries
        self::assertCount(0, $issues, 'Should not suggest caching for UPDATE queries');
    }

    #[Test]
    public function it_does_not_suggest_caching_for_delete_queries(): void
    {
        // Arrange: Same DELETE query executed 4 times
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 4; $i++) {
            $queries->addQuery('DELETE FROM sessions WHERE expired_at < ?', 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT suggest caching for DELETE queries
        self::assertCount(0, $issues, 'Should not suggest caching for DELETE queries');
    }

    #[Test]
    public function it_only_suggests_caching_for_select_queries(): void
    {
        // Arrange: Mix of SELECT, INSERT, UPDATE queries
        $queries = QueryDataBuilder::create();

        // 5 SELECT queries (should trigger suggestion)
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery("SELECT * FROM products WHERE id = {$i}", 10.0);
        }

        // 5 INSERT queries (should NOT trigger suggestion)
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery('INSERT INTO orders (status, total) VALUES (?, ?)', 10.0);
        }

        // 5 UPDATE queries (should NOT trigger suggestion)
        for ($i = 1; $i <= 5; $i++) {
            $queries->addQuery('UPDATE users SET last_login = ? WHERE id = ?', 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should only detect SELECT query caching opportunity
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should only suggest caching for SELECT queries');

        // Verify it's about the SELECT query by checking the suggestion code
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);
        self::assertStringContainsString('SELECT', $suggestion->getCode());
    }

    #[Test]
    public function it_does_not_suggest_caching_for_insert_on_static_tables(): void
    {
        // Arrange: INSERT query on static table
        // This should not trigger suggestion because INSERTs cannot be cached
        $queries = QueryDataBuilder::create()
            ->addQuery('INSERT INTO countries (code, name) VALUES (?, ?)', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT suggest caching
        self::assertCount(0, $issues, 'Should not suggest caching for INSERT on static tables');
    }

    #[Test]
    public function it_distinguishes_queries_with_different_parameters(): void
    {
        // Arrange: Same SQL structure but DIFFERENT parameters (not true duplicates)
        // This tests the Sylius case: 4 findOneBySlug() with different slugs
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM taxon WHERE slug = ? AND locale = ?',
                [],
                0.3,
            )
            ->addQueryWithBacktrace(
                'SELECT * FROM taxon WHERE slug = ? AND locale = ?',
                [],
                0.35,
            )
            ->addQueryWithBacktrace(
                'SELECT * FROM taxon WHERE slug = ? AND locale = ?',
                [],
                0.32,
            )
            ->addQueryWithBacktrace(
                'SELECT * FROM taxon WHERE slug = ? AND locale = ?',
                [],
                0.4,
            )
            ->build();

        // Without params in QueryData, the analyzer falls back to SQL-only comparison
        // This is the old behavior and should still detect 4 "duplicates"
        $issues = $this->analyzer->analyze($queries);

        // Old behavior: detects as duplicates because params not available
        self::assertCount(1, $issues, 'Without params, should detect based on SQL only');
        self::assertStringContainsString('4 Times', $issues->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_detects_true_duplicates_with_same_parameters(): void
    {
        // Arrange: Same SQL + SAME parameters = TRUE duplicate
        $builder = QueryDataBuilder::create();

        // Execute same query with same params 4 times
        for ($i = 0; $i < 4; $i++) {
            // Note: QueryDataBuilder needs support for params
            // For now, we create raw array data
            $builder->addQuery('SELECT * FROM taxon WHERE slug = ? AND locale = ?', 0.3);
        }

        $queries = $builder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect as frequent (true duplicate)
        self::assertCount(1, $issues, 'Should detect true duplicates with same params');
        self::assertStringContainsString('4 Times', $issues->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_ignores_false_positives_with_different_parameters(): void
    {
        // This test requires QueryData with params support
        // We'll use fromArray to create proper QueryData objects with params
        $rawQueries = [
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['voyage', 'fr']],
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.35, 'params' => ['hotel', 'fr']],
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.32, 'params' => ['restaurant', 'fr']],
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.4, 'params' => ['transport', 'fr']],
        ];

        $queryDataObjects = array_map(
            fn ($q) => \AhmedBhs\DoctrineDoctor\DTO\QueryData::fromArray($q),
            $rawQueries,
        );

        $queries = \AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection::fromArray($queryDataObjects);

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect as duplicates (different params = different queries)
        self::assertCount(0, $issues, 'Should NOT flag queries with different parameters as duplicates');
    }

    #[Test]
    public function it_detects_true_duplicates_with_params(): void
    {
        // Arrange: Same SQL + SAME params = TRUE duplicate
        $rawQueries = [
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['voyage', 'fr']],
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['voyage', 'fr']],
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['voyage', 'fr']],
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['voyage', 'fr']],
        ];

        $queryDataObjects = array_map(
            fn ($q) => \AhmedBhs\DoctrineDoctor\DTO\QueryData::fromArray($q),
            $rawQueries,
        );

        $queries = \AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection::fromArray($queryDataObjects);

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect as frequent (true duplicate with same params)
        self::assertCount(1, $issues, 'Should detect true duplicates with same params');
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('4 Times', $issue->getTitle());
        self::assertStringContainsString('4 times', $issue->getDescription());
    }

    #[Test]
    public function it_handles_mixed_duplicates_correctly(): void
    {
        // Arrange: Mix of true duplicates and different queries
        $rawQueries = [
            // TRUE duplicate: 3x same query with params ["voyage", "fr"]
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['voyage', 'fr']],
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['voyage', 'fr']],
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['voyage', 'fr']],
            // Different query (different params)
            ['sql' => 'SELECT * FROM taxon WHERE slug = ? AND locale = ?', 'executionMS' => 0.3, 'params' => ['hotel', 'fr']],
            // Another TRUE duplicate: 3x same query with params [123]
            ['sql' => 'SELECT * FROM product WHERE id = ?', 'executionMS' => 0.5, 'params' => [123]],
            ['sql' => 'SELECT * FROM product WHERE id = ?', 'executionMS' => 0.5, 'params' => [123]],
            ['sql' => 'SELECT * FROM product WHERE id = ?', 'executionMS' => 0.5, 'params' => [123]],
        ];

        $queryDataObjects = array_map(
            fn ($q) => \AhmedBhs\DoctrineDoctor\DTO\QueryData::fromArray($q),
            $rawQueries,
        );

        $queries = \AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection::fromArray($queryDataObjects);

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect 2 separate duplicate groups
        self::assertCount(2, $issues, 'Should detect 2 separate duplicate groups');

        $titles = array_map(fn ($i) => $i->getTitle(), $issues->toArray());
        self::assertContains('Frequent Query Executed 3 Times', $titles, 'Should detect taxon duplicate (3 times)');
        self::assertContains('Frequent Query Executed 3 Times', $titles, 'Should detect product duplicate (3 times)');
    }
}
