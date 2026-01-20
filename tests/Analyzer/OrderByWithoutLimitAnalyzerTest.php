<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\OrderByWithoutLimitAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for OrderByWithoutLimitAnalyzer.
 *
 * This analyzer detects ORDER BY clauses without LIMIT, which can cause:
 * - Massive table scans (sorting millions of rows unnecessarily)
 * - High memory usage (entire result set loaded)
 * - Slow response times (full table sort)
 */
final class OrderByWithoutLimitAnalyzerTest extends TestCase
{
    private OrderByWithoutLimitAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $this->analyzer = new OrderByWithoutLimitAnalyzer($suggestionFactory);
    }

    #[Test]
    public function it_returns_empty_collection_when_no_issues(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users')
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC LIMIT 20')
            ->addQuery('SELECT * FROM products WHERE status = "active"')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_order_by_without_limit(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC', 100.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('order_by_without_limit', $data['type']);
        self::assertEquals('ORDER BY Without LIMIT Detected', $issue->getTitle());
        self::assertStringContainsString('ORDER BY without LIMIT', $issue->getDescription());
        self::assertStringContainsString('created_at DESC', $issue->getDescription());
    }

    #[Test]
    public function it_ignores_order_by_with_limit(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC LIMIT 20')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_order_by_with_offset(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC OFFSET 20')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_order_by_with_limit_and_offset(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC LIMIT 20 OFFSET 40')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_multiple_order_by_columns(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY status ASC, created_at DESC', 150.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('status ASC, created_at DESC', $issue->getDescription());
    }

    #[Test]
    public function it_sets_critical_severity_for_slow_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC', 600.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('critical', $data['severity']);
    }

    #[Test]
    public function it_sets_warning_severity_for_moderate_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC', 200.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('warning', $data['severity']);
    }

    #[Test]
    public function it_sets_info_severity_for_fast_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC', 50.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('info', $data['severity']);
    }

    #[Test]
    public function it_deduplicates_same_order_by_clause(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE status = "pending" ORDER BY created_at DESC', 100.0)
            ->addQuery('SELECT * FROM orders WHERE status = "completed" ORDER BY created_at DESC', 100.0)
            ->addQuery('SELECT * FROM orders WHERE user_id = 5 ORDER BY created_at DESC', 100.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should deduplicate based on ORDER BY clause (MD5 hash)
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_detects_different_order_by_clauses(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC', 100.0)
            ->addQuery('SELECT * FROM orders ORDER BY status ASC', 100.0)
            ->addQuery('SELECT * FROM orders ORDER BY total DESC', 100.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should detect 3 different ORDER BY clauses
        self::assertCount(3, $issues);
    }

    #[Test]
    public function it_handles_array_format_query(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM orders ORDER BY created_at DESC',
                [['file' => 'OrderRepository.php', 'line' => 42]],
                100.0,
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $backtrace = $issue->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertCount(1, $backtrace);
    }

    #[Test]
    public function it_handles_case_insensitive_order_by(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders order by created_at desc', 100.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_queries_without_order_by(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE status = "pending"')
            ->addQuery('SELECT COUNT(*) FROM users')
            ->addQuery('INSERT INTO logs (message) VALUES ("test")')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC', 100.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertIsArray($suggestion->toArray());
    }

    #[Test]
    public function it_includes_execution_time_in_description(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC', 250.5)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('250.50ms', $issue->getDescription());
    }

    #[Test]
    public function it_has_correct_name_and_description(): void
    {
        self::assertEquals('ORDER BY Without LIMIT Analyzer', $this->analyzer->getName());
        self::assertEquals('Detects ORDER BY clauses without LIMIT that can cause unnecessary sorting of large datasets', $this->analyzer->getDescription());
    }

    #[Test]
    public function it_renders_suggestion_template_without_errors(): void
    {
        // This test verifies that the template can be rendered with the actual context provided
        // Previously, the template expected 'query' but the context provided 'original_query'
        // Use PhpTemplateRenderer to load actual template files
        $renderer = new PhpTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $analyzer = new OrderByWithoutLimitAnalyzer($suggestionFactory);

        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY created_at DESC', 100.0)
            ->build();

        $issues = $analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        // Verify suggestion can be converted to array without errors
        self::assertNotNull($suggestion);
        $suggestionArray = $suggestion->toArray();

        // Verify the template was rendered successfully (no render_error)
        self::assertIsArray($suggestionArray);
        self::assertArrayNotHasKey('render_error', $suggestionArray, 'Template rendering failed: ' . ($suggestionArray['render_error'] ?? 'Unknown error'));

        // Verify the template was rendered with code and description
        self::assertArrayHasKey('code', $suggestionArray);
        self::assertArrayHasKey('description', $suggestionArray);

        // Verify rendered content contains expected elements
        self::assertStringContainsString('ORDER BY', $suggestionArray['code']);
        self::assertStringContainsString('LIMIT', $suggestionArray['code']);
    }

    #[Test]
    public function it_handles_order_by_with_complex_expressions(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY CASE WHEN status = "urgent" THEN 1 ELSE 2 END, created_at DESC', 150.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('CASE WHEN', $issue->getDescription());
    }

    #[Test]
    public function it_handles_order_by_with_table_aliases(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT o.* FROM orders o ORDER BY o.created_at DESC', 100.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_order_by_with_function_calls(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY LOWER(customer_name) ASC', 100.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('LOWER(customer_name)', $issue->getDescription());
    }

    #[Test]
    public function it_respects_severity_threshold_boundaries(): void
    {
        // Test exactly 500ms (should be critical)
        $queries1 = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY id', 501.0)
            ->build();
        $issues1 = $this->analyzer->analyze($queries1);
        self::assertEquals('critical', $issues1->toArray()[0]->getData()['severity']);

        // Test exactly 100ms (should be info)
        $queries2 = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY id', 100.0)
            ->build();
        $issues2 = $this->analyzer->analyze($queries2);
        self::assertEquals('info', $issues2->toArray()[0]->getData()['severity']);

        // Test 101ms (should be warning)
        $queries3 = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders ORDER BY id', 101.0)
            ->build();
        $issues3 = $this->analyzer->analyze($queries3);
        self::assertEquals('warning', $issues3->toArray()[0]->getData()['severity']);
    }

    #[Test]
    public function it_skips_single_result_queries_with_good_performance(): void
    {
        // Fast query (< 10ms) with getOneOrNullResult should be skipped (false positive)
        $backtrace = [
            ['file' => 'Query.php', 'line' => 296, 'class' => 'Doctrine\\ORM\\Query', 'function' => '_doExecute'],
            ['file' => 'AbstractQuery.php', 'line' => 886, 'class' => 'Doctrine\\ORM\\AbstractQuery', 'function' => 'execute'],
            ['file' => 'AbstractQuery.php', 'line' => 737, 'class' => 'Doctrine\\ORM\\AbstractQuery', 'function' => 'getOneOrNullResult'],
            ['file' => 'TaxonRepository.php', 'line' => 85, 'class' => 'TaxonRepository', 'function' => 'findOneBySlug'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT s0_.id AS id_0 FROM sylius_taxon s0_ INNER JOIN sylius_taxon_translation s1_ ON s0_.id = s1_.translatable_id WHERE s0_.enabled = ? AND s1_.slug = ? AND s1_.locale = ? ORDER BY s0_.id ASC',
                $backtrace,
                5.0, // Fast query < 10ms - should be skipped
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should be skipped (no issue) because it's single_result + fast
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_single_result_queries_with_poor_performance(): void
    {
        // Slow query (>= 10ms) with getOneOrNullResult should still be detected
        $backtrace = [
            ['file' => 'AbstractQuery.php', 'line' => 737, 'class' => 'Doctrine\\ORM\\AbstractQuery', 'function' => 'getOneOrNullResult'],
            ['file' => 'UserRepository.php', 'line' => 50, 'class' => 'UserRepository', 'function' => 'findOneByEmail'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM users ORDER BY id ASC',
                $backtrace,
                15.0, // Slow enough to be flagged
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        // Should be INFO severity for single_result context
        self::assertEquals('info', $data['severity']);
        self::assertStringContainsString('Single-Result Query', $issue->getTitle());
        self::assertStringContainsString('setMaxResults(1)', $issue->getDescription());
    }

    #[Test]
    public function it_detects_array_result_queries_without_limit(): void
    {
        // Array result methods (getResult, findBy) should be flagged more severely
        $backtrace = [
            ['file' => 'AbstractQuery.php', 'line' => 886, 'class' => 'Doctrine\\ORM\\AbstractQuery', 'function' => 'execute'],
            ['file' => 'Query.php', 'line' => 737, 'class' => 'Doctrine\\ORM\\Query', 'function' => 'getResult'],
            ['file' => 'OrderRepository.php', 'line' => 42, 'class' => 'OrderRepository', 'function' => 'findAllOrdered'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM orders ORDER BY created_at DESC',
                $backtrace,
                50.0, // Moderate speed
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        // Should be WARNING severity for array_result context (always warn)
        self::assertEquals('warning', $data['severity']);
        self::assertStringContainsString('Array Query', $issue->getTitle());
        self::assertStringContainsString('array of results', $issue->getDescription());
        self::assertStringContainsString('Add LIMIT', $issue->getDescription());
    }

    #[Test]
    public function it_detects_get_single_result_as_single_result_context(): void
    {
        // getSingleResult should also be detected as single_result context
        $backtrace = [
            ['file' => 'AbstractQuery.php', 'line' => 737, 'class' => 'Doctrine\\ORM\\AbstractQuery', 'function' => 'getSingleResult'],
            ['file' => 'UserRepository.php', 'line' => 60, 'class' => 'UserRepository', 'function' => 'getByUsername'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM users WHERE username = ? ORDER BY id',
                $backtrace,
                5.0, // Fast - should be skipped
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should be skipped (fast single_result query)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_handles_very_slow_single_result_query(): void
    {
        // Very slow (> 100ms) single_result should get WARNING severity
        $backtrace = [
            ['file' => 'AbstractQuery.php', 'line' => 737, 'class' => 'Doctrine\\ORM\\AbstractQuery', 'function' => 'getOneOrNullResult'],
            ['file' => 'ProductRepository.php', 'line' => 90, 'class' => 'ProductRepository', 'function' => 'findOneBySlug'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM products ORDER BY name',
                $backtrace,
                120.0, // Very slow
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        // Should be WARNING severity (> 100ms threshold)
        self::assertEquals('warning', $data['severity']);
        self::assertStringContainsString('120.00ms', $issue->getDescription());
    }

    #[Test]
    public function it_handles_very_slow_array_result_query(): void
    {
        // Very slow (> 500ms) array_result should get CRITICAL severity
        $backtrace = [
            ['file' => 'Query.php', 'line' => 737, 'class' => 'Doctrine\\ORM\\Query', 'function' => 'getResult'],
            ['file' => 'OrderRepository.php', 'line' => 100, 'class' => 'OrderRepository', 'function' => 'findAll'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM orders ORDER BY created_at DESC',
                $backtrace,
                550.0, // Very slow
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        // Should be CRITICAL severity (> 500ms threshold for array results)
        self::assertEquals('critical', $data['severity']);
        self::assertStringContainsString('550.00ms', $issue->getDescription());
    }

    #[Test]
    public function it_detects_find_by_as_array_result_context(): void
    {
        // Repository findBy method should be detected as array_result
        $backtrace = [
            ['file' => 'EntityRepository.php', 'line' => 200, 'class' => 'Doctrine\\ORM\\EntityRepository', 'function' => 'findBy'],
            ['file' => 'UserController.php', 'line' => 42, 'class' => 'UserController', 'function' => 'listUsers'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM users ORDER BY created_at DESC',
                $backtrace,
                80.0,
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Should be detected as array_result context
        self::assertStringContainsString('Array Query', $issue->getTitle());
    }
}
