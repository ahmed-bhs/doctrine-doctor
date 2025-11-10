<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\NullComparisonAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\TestCase;

final class NullComparisonAnalyzerTest extends TestCase
{
    private NullComparisonAnalyzer $nullComparisonAnalyzer;

    protected function setUp(): void
    {
        $inMemoryTemplateRenderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($inMemoryTemplateRenderer);
        $this->nullComparisonAnalyzer = new NullComparisonAnalyzer($suggestionFactory);
    }

    public function test_detects_equal_null_comparison(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus = NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('Incorrect NULL Comparison', $issue->getTitle());
        self::assertStringContainsString('bonus = NULL', $issue->getDescription());
        self::assertStringContainsString('IS NULL', $issue->getDescription());
    }

    public function test_detects_not_equal_null_comparison(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus != NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('bonus != NULL', $issue->getDescription());
        self::assertStringContainsString('IS NOT NULL', $issue->getDescription());
    }

    public function test_detects_diamond_not_equal_operator(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus <> NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('bonus <> NULL', $issue->getDescription());
    }

    public function test_detects_null_comparison_with_field_alias(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM employees e WHERE e.bonus = NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('e.bonus = NULL', $issue->getDescription());
        self::assertStringContainsString('e.bonus IS NULL', $issue->getDescription());
    }

    public function test_ignores_correct_is_null_syntax(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus IS NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_ignores_correct_is_not_null_syntax(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus IS NOT NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_detects_multiple_null_comparisons(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus = NULL OR department = NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(2, $issueCollection);
    }

    public function test_handles_case_insensitive(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus = null',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }

    public function test_deduplicates_same_comparison(): void
    {
        $query1 = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus = NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $query2 = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus = NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query1, $query2]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        // Should deduplicate
        self::assertCount(1, $issueCollection);
    }

    public function test_handles_array_format_query(): void
    {
        $query = new QueryData(
            sql: 'SELECT * FROM employees WHERE bonus = NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: null,
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query]);
        $issueCollection = $this->nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }
}
