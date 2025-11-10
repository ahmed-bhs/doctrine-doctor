<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\IneffectiveLikeAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\TestCase;

final class IneffectiveLikeAnalyzerTest extends TestCase
{
    private IneffectiveLikeAnalyzer $ineffectiveLikeAnalyzer;

    protected function setUp(): void
    {
        $inMemoryTemplateRenderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($inMemoryTemplateRenderer);
        $this->ineffectiveLikeAnalyzer = new IneffectiveLikeAnalyzer($suggestionFactory);
    }

    public function test_detects_like_with_leading_and_trailing_wildcard(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE '%John%'",
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('Ineffective LIKE Pattern Detected', $issue->getTitle());
        self::assertStringContainsString('leading wildcard', $issue->getDescription());
        self::assertStringContainsString('%John%', $issue->getDescription());
    }

    public function test_detects_like_with_leading_wildcard_only(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE email LIKE '%@example.com'",
            executionTime: QueryExecutionTime::fromMilliseconds(80.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('%@example.com', $issue->getDescription());
    }

    public function test_ignores_like_with_trailing_wildcard_only(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE 'John%'",
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        // Trailing wildcard only is OK - can use index
        self::assertCount(0, $issueCollection);
    }

    public function test_ignores_like_without_wildcard(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE 'John'",
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_detects_multiple_like_patterns(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE '%John%' OR email LIKE '%@example.com'",
            executionTime: QueryExecutionTime::fromMilliseconds(200.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(2, $issueCollection);
    }

    public function test_sets_critical_severity_for_slow_queries(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE '%search%'",
            executionTime: QueryExecutionTime::fromMilliseconds(250.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('critical', $issue->getSeverity()->value);
    }

    public function test_sets_warning_severity_for_moderate_queries(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE '%search%'",
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('warning', $issue->getSeverity()->value);
    }

    public function test_sets_info_severity_for_fast_queries(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE '%search%'",
            executionTime: QueryExecutionTime::fromMilliseconds(30.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('info', $issue->getSeverity()->value);
    }

    public function test_deduplicates_same_pattern(): void
    {
        $query1 = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE '%John%' AND status = 'active'",
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $query2 = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE '%John%' AND city = 'Paris'",
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query1, $query2]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        // Should deduplicate based on pattern
        self::assertCount(1, $issueCollection);
    }

    public function test_handles_array_format_query(): void
    {
        $query = new QueryData(
            sql: "SELECT * FROM users WHERE name LIKE '%search%'",
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
            backtrace: null,
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }

    public function test_handles_case_insensitive_like(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE name like '%John%'",
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }

    public function test_handles_double_quotes(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM users WHERE name LIKE "%John%"',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }
}
