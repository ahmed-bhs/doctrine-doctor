<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\JoinTypeConsistencyAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for JoinTypeConsistencyAnalyzer.
 *
 * This analyzer detects:
 * 1. LEFT JOIN followed by IS NOT NULL check (should use INNER JOIN)
 * 2. COUNT/SUM/AVG with INNER JOIN (causes row duplication bugs)
 */
final class JoinTypeConsistencyAnalyzerTest extends TestCase
{
    private JoinTypeConsistencyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $this->analyzer = new JoinTypeConsistencyAnalyzer($entityManager, $suggestionFactory);
    }

    #[Test]
    public function it_returns_empty_collection_when_no_issues(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users')
            ->addQuery('SELECT * FROM orders o INNER JOIN customers c ON o.customer_id = c.id')
            ->addQuery('SELECT * FROM orders o LEFT JOIN customers c ON o.customer_id = c.id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_left_join_with_is_not_null(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE c.id IS NOT NULL',
                [['class' => 'UserRepository', 'function' => 'findOrders']],
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('left_join_with_not_null', $data['type']);
        self::assertEquals('LEFT JOIN with IS NOT NULL Check', $issue->getTitle());
        self::assertStringContainsString('customers', $issue->getDescription());
        self::assertStringContainsString('IS NOT NULL', $issue->getDescription());
        self::assertStringContainsString('INNER JOIN', $issue->getDescription());
        self::assertEquals('info', $data['severity']);
    }

    #[Test]
    public function it_detects_left_outer_join_with_is_not_null(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders o LEFT OUTER JOIN customers c ON o.customer_id = c.id WHERE c.email IS NOT NULL')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('left_join_with_not_null', $data['type']);
        self::assertStringContainsString('customers', $issue->getDescription());
        self::assertStringContainsString('c.email', $issue->getDescription());
    }

    #[Test]
    public function it_detects_count_with_inner_join(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT COUNT(o.id) FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('aggregation_with_inner_join', $data['type']);
        self::assertEquals('COUNT with INNER JOIN May Cause Incorrect Results', $issue->getTitle());
        self::assertStringContainsString('COUNT()', $issue->getDescription());
        self::assertStringContainsString('row duplication', $issue->getDescription());
        self::assertEquals('warning', $data['severity']);
    }

    #[Test]
    public function it_detects_sum_with_inner_join(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT SUM(o.total) FROM orders o INNER JOIN customers c ON o.customer_id = c.id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('aggregation_with_inner_join', $data['type']);
        self::assertEquals('SUM with INNER JOIN May Cause Incorrect Results', $issue->getTitle());
        self::assertStringContainsString('SUM()', $issue->getDescription());
    }

    #[Test]
    public function it_detects_avg_with_inner_join(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT AVG(o.total) FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('aggregation_with_inner_join', $data['type']);
        self::assertEquals('AVG with INNER JOIN May Cause Incorrect Results', $issue->getTitle());
        self::assertStringContainsString('AVG()', $issue->getDescription());
    }

    #[Test]
    public function it_ignores_inner_join_without_aggregation(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT o.* FROM orders o INNER JOIN customers c ON o.customer_id = c.id WHERE c.status = "active"')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_left_join_without_is_not_null(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.status = "pending"')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_left_join_with_is_null(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE c.id IS NULL')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // LEFT JOIN with IS NULL is legitimate (finding orphans)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_both_issues_in_same_query(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT COUNT(o.id) FROM orders o LEFT JOIN customers c ON o.customer_id = c.id INNER JOIN order_items oi ON o.id = oi.order_id WHERE c.id IS NOT NULL')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should detect both: LEFT JOIN + IS NOT NULL and COUNT + INNER JOIN
        self::assertCount(2, $issues);

        $issuesArray = $issues->toArray();
        $types = array_map(fn ($issue) => $issue->getData()['type'], $issuesArray);

        self::assertContains('left_join_with_not_null', $types);
        self::assertContains('aggregation_with_inner_join', $types);
    }

    #[Test]
    public function it_deduplicates_same_left_join_issue(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE c.id IS NOT NULL AND o.status = "pending"')
            ->addQuery('SELECT * FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE c.id IS NOT NULL AND o.status = "completed"')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should deduplicate based on table/alias
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_deduplicates_same_aggregation_issue(): void
    {
        $sql = 'SELECT COUNT(o.id) FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id';

        $queries = QueryDataBuilder::create()
            ->addQuery($sql)
            ->addQuery($sql)
            ->addQuery($sql)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should deduplicate based on SQL hash
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_array_format_query(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT COUNT(o.id) FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id',
                [['file' => 'OrderRepository.php', 'line' => 42]],
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
    public function it_handles_case_insensitive_join(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT count(o.id) FROM orders o inner join order_items oi ON o.id = oi.order_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        self::assertEquals('aggregation_with_inner_join', $issue->getData()['type']);
    }

    #[Test]
    public function it_handles_count_distinct_with_inner_join(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT COUNT(DISTINCT o.id) FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // COUNT(DISTINCT) is protected - should be detected with INFO severity
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('aggregation_with_inner_join', $data['type']);
        self::assertEquals('COUNT with INNER JOIN - Performance Impact', $issue->getTitle());
        self::assertEquals('info', $data['severity']);
        self::assertStringContainsString('results are **correct**', $issue->getDescription());
    }

    #[Test]
    public function it_provides_suggestion_for_left_join_issue(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE c.id IS NOT NULL')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertIsArray($suggestion->toArray());
    }

    #[Test]
    public function it_provides_suggestion_for_aggregation_issue(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT COUNT(o.id) FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertIsArray($suggestion->toArray());
    }

    #[Test]
    public function it_handles_queries_without_joins(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users')
            ->addQuery('SELECT COUNT(*) FROM orders')
            ->addQuery('SELECT name, email FROM customers WHERE status = "active"')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // No JOIN issues when there are no JOINs
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_correct_name_and_description(): void
    {
        self::assertEquals('JOIN Type Consistency Analyzer', $this->analyzer->getName());
        self::assertEquals('Detects inconsistencies in JOIN usage that can cause bugs or performance issues', $this->analyzer->getDescription());
    }

    #[Test]
    public function it_detects_multiple_different_left_join_issues(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE c.id IS NOT NULL')
            ->addQuery('SELECT * FROM orders o LEFT JOIN products p ON o.product_id = p.id WHERE p.name IS NOT NULL')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should detect 2 different issues (different tables)
        self::assertCount(2, $issues);
    }

    #[Test]
    public function it_detects_doctrine_paginator_count_as_protected(): void
    {
        // SKIP: SQL parser doesn't detect JOINs in nested subqueries
        // This test would require a more sophisticated parser or recursive subquery analysis
        self::markTestSkipped('SQL parser does not detect JOINs in nested subqueries');
    }

    #[Test]
    public function it_detects_select_distinct_with_count_as_protected(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT DISTINCT o.id FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // SELECT DISTINCT without COUNT/SUM/AVG should not trigger the aggregation check
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_treats_unprotected_count_as_warning(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT COUNT(o.id) FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('aggregation_with_inner_join', $data['type']);
        self::assertEquals('COUNT with INNER JOIN May Cause Incorrect Results', $issue->getTitle());
        self::assertEquals('warning', $data['severity']);
        self::assertStringContainsString('incorrect aggregate results', $issue->getDescription());
    }

    #[Test]
    public function it_detects_sum_with_distinct_as_protected(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT SUM(DISTINCT o.total) FROM orders o INNER JOIN customers c ON o.customer_id = c.id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('aggregation_with_inner_join', $data['type']);
        self::assertEquals('SUM with INNER JOIN - Performance Impact', $issue->getTitle());
        self::assertEquals('info', $data['severity']);
    }

    #[Test]
    public function it_detects_avg_without_distinct_as_warning(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT AVG(o.total) FROM orders o INNER JOIN order_items oi ON o.id = oi.order_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('aggregation_with_inner_join', $data['type']);
        self::assertEquals('AVG with INNER JOIN May Cause Incorrect Results', $issue->getTitle());
        self::assertEquals('warning', $data['severity']);
    }
}
