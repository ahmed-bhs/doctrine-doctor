<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\DivisionByZeroAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\TestCase;

final class DivisionByZeroAnalyzerTest extends TestCase
{
    private DivisionByZeroAnalyzer $divisionByZeroAnalyzer;

    protected function setUp(): void
    {
        $inMemoryTemplateRenderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($inMemoryTemplateRenderer);
        $this->divisionByZeroAnalyzer = new DivisionByZeroAnalyzer($suggestionFactory);
    }

    public function test_detects_division_by_zero_in_simple_query(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT revenue / quantity FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->divisionByZeroAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('Potential Division By Zero Error', $issue->getTitle());
        self::assertStringContainsString('revenue / quantity', $issue->getDescription());
        self::assertStringContainsString('NULLIF', $issue->getDescription());
    }

    public function test_detects_division_with_field_alias(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT s.revenue / s.quantity FROM sales s',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->divisionByZeroAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('s.revenue / s.quantity', $issue->getDescription());
    }

    public function test_ignores_division_by_non_zero_constant(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT revenue / 100 FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->divisionByZeroAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_ignores_protected_division(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT revenue / NULLIF(quantity, 0) FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->divisionByZeroAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_ignores_case_when_protection(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT CASE WHEN quantity = 0 THEN 0 ELSE revenue / quantity END FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->divisionByZeroAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_detects_multiple_divisions(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT revenue / quantity, cost / items FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->divisionByZeroAnalyzer->analyze($queryDataCollection);

        self::assertCount(2, $issueCollection);
    }

    public function test_deduplicates_same_division(): void
    {
        $query1 = new QueryData(
            sql: 'SELECT revenue / quantity FROM sales WHERE id = 1',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $query2 = new QueryData(
            sql: 'SELECT revenue / quantity FROM sales WHERE id = 2',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query1, $query2]);
        $issueCollection = $this->divisionByZeroAnalyzer->analyze($queryDataCollection);

        // Should deduplicate - same division operation
        self::assertCount(1, $issueCollection);
    }

    public function test_handles_array_format_query(): void
    {
        $query = new QueryData(
            sql: 'SELECT revenue / quantity FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: null,
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query]);
        $issueCollection = $this->divisionByZeroAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }
}
