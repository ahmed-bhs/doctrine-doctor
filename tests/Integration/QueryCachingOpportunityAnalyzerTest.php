<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\QueryCachingOpportunityAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\TestCase;

final class QueryCachingOpportunityAnalyzerTest extends TestCase
{
    private QueryCachingOpportunityAnalyzer $queryCachingOpportunityAnalyzer;

    protected function setUp(): void
    {
        $inMemoryTemplateRenderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($inMemoryTemplateRenderer);
        $this->queryCachingOpportunityAnalyzer = new QueryCachingOpportunityAnalyzer($suggestionFactory);
    }

    public function test_detects_frequent_query(): void
    {
        // Same query executed 5 times
        $queries = [];
        for ($i = 0; $i < 5; $i++) {
            $queries[] = new QueryData(
                sql: 'SELECT * FROM products WHERE id = 123',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            );
        }

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertNotNull($issue);
        self::assertSame('Frequent Query Executed 5 Times', $issue->getTitle());
        self::assertStringContainsString('5 times', (string) $issue->getDescription());
        self::assertStringContainsString('useResultCache', (string) $issue->getDescription());
    }

    public function test_detects_very_frequent_query_as_critical(): void
    {
        // Same query executed 12 times
        $queries = [];
        for ($i = 0; $i < 12; $i++) {
            $queries[] = new QueryData(
                sql: 'SELECT * FROM users WHERE status = "active"',
                executionTime: QueryExecutionTime::fromMilliseconds(8.0),
                params: [],
            );
        }

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertNotNull($issue);
        self::assertSame('Frequent Query Executed 12 Times', $issue->getTitle());
        self::assertSame('critical', $issue->getSeverity()->value);
    }

    public function test_normalizes_queries_with_different_values(): void
    {
        // Structurally identical queries with different values
        $queries = [
            new QueryData(
                sql: 'SELECT * FROM products WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
            new QueryData(
                sql: 'SELECT * FROM products WHERE id = 2',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
            new QueryData(
                sql: 'SELECT * FROM products WHERE id = 3',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
            new QueryData(
                sql: 'SELECT * FROM products WHERE id = 42',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        // Should detect as single frequent query (normalized)
        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('Frequent Query Executed 4 Times', $issue->getTitle());
    }

    public function test_ignores_infrequent_queries(): void
    {
        // Query executed only 2 times (below threshold of 3)
        $queries = [
            new QueryData(
                sql: 'SELECT * FROM orders WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
            new QueryData(
                sql: 'SELECT * FROM orders WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_detects_static_table_query(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM countries ORDER BY name',
            executionTime: QueryExecutionTime::fromMilliseconds(5.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame("Query on Static Table 'countries'", $issue->getTitle());
        self::assertStringContainsString('static', $issue->getDescription());
        self::assertStringContainsString('countries', $issue->getDescription());
        self::assertSame('info', $issue->getSeverity()->value);
    }

    public function test_detects_multiple_static_tables(): void
    {
        $queries = [
            new QueryData(
                sql: 'SELECT * FROM countries ORDER BY name',
                executionTime: QueryExecutionTime::fromMilliseconds(5.0),
                params: [],
            ),
            new QueryData(
                sql: 'SELECT * FROM currencies WHERE active = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(5.0),
                params: [],
            ),
            new QueryData(
                sql: 'SELECT * FROM languages',
                executionTime: QueryExecutionTime::fromMilliseconds(5.0),
                params: [],
            ),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        // Should detect 3 static table queries
        self::assertCount(3, $issueCollection);
    }

    public function test_detects_static_table_in_join(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT u.* FROM users u JOIN roles r ON u.role_id = r.id',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('roles', $issue->getTitle());
    }

    public function test_does_not_duplicate_static_table_issue(): void
    {
        // Same static table queried multiple times (below frequency threshold)
        $queries = [
            new QueryData(
                sql: 'SELECT * FROM countries WHERE code = "FR"',
                executionTime: QueryExecutionTime::fromMilliseconds(5.0),
                params: [],
            ),
            new QueryData(
                sql: 'SELECT * FROM countries WHERE code = "US"',
                executionTime: QueryExecutionTime::fromMilliseconds(5.0),
                params: [],
            ),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        // Should report only static table issue (not frequent enough for frequency issue)
        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('countries', $issue->getTitle());
    }

    public function test_detects_both_frequent_and_static_table(): void
    {
        // Frequent query on a static table
        $queries = [];
        for ($i = 0; $i < 5; $i++) {
            $queries[] = new QueryData(
                sql: 'SELECT * FROM settings WHERE key = "app_name"',
                executionTime: QueryExecutionTime::fromMilliseconds(5.0),
                params: [],
            );
        }

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        // Should report both: frequent query AND static table
        self::assertCount(2, $issueCollection);

        $titles = array_map(fn (IssueInterface $issue): string => $issue->getTitle(), iterator_to_array($issueCollection));
        self::assertContains('Frequent Query Executed 5 Times', $titles);
        self::assertContains("Query on Static Table 'settings'", $titles);
    }

    public function test_handles_array_format_query(): void
    {
        $queries = [];
        for ($i = 0; $i < 5; $i++) {
            $queries[] = new QueryData(
                sql: 'SELECT * FROM products WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
                backtrace: null,
            );
        }

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }

    public function test_normalizes_string_literals(): void
    {
        // Queries with different string values should be normalized
        $queries = [
            new QueryData(
                sql: "SELECT * FROM users WHERE name = 'John'",
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
            new QueryData(
                sql: "SELECT * FROM users WHERE name = 'Jane'",
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
            new QueryData(
                sql: "SELECT * FROM users WHERE name = 'Bob'",
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            ),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        // Should detect as single frequent query
        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('Frequent Query Executed 3 Times', $issue->getTitle());
    }

    public function test_ignores_non_static_tables(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE status = "pending"',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        // Should not detect static table issue for 'orders'
        self::assertCount(0, $issueCollection);
    }

    public function test_calculates_total_execution_time(): void
    {
        $queries = [];
        for ($i = 0; $i < 5; $i++) {
            $queries[] = new QueryData(
                sql: 'SELECT * FROM products WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(10.0),
                params: [],
            );
        }

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->queryCachingOpportunityAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertNotNull($issue);

        // Total time should be mentioned (5 × 10ms = 50ms)
        self::assertStringContainsString('50', (string) $issue->getDescription());
    }
}
