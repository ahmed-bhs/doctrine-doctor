<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\OrderByWithoutLimitAnalyzer;
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
}
