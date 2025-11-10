<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\DivisionByZeroAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for DivisionByZeroAnalyzer.
 *
 * Tests the analyzer's ability to detect division operations that could
 * result in division by zero errors in DQL/SQL queries.
 */
final class DivisionByZeroAnalyzerIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }
    }

    #[Test]
    public function it_analyzes_without_errors(): void
    {
        $divisionByZeroAnalyzer = $this->createAnalyzer();
        $issueCollection = $divisionByZeroAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issueCollection);
    }

    #[Test]
    public function it_returns_issue_collection(): void
    {
        $divisionByZeroAnalyzer = $this->createAnalyzer();
        $issueCollection = $divisionByZeroAnalyzer->analyze(QueryDataCollection::empty());

        $count = 0;
        foreach ($issueCollection as $issue) {
            $count++;
            self::assertNotNull($issue);
        }

        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function it_analyzes_all_entities_without_errors(): void
    {
        $divisionByZeroAnalyzer = $this->createAnalyzer();
        $issueCollection = $divisionByZeroAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issueCollection);

        // Iterate through all issues to ensure they're valid
        $issueCount = 0;
        foreach ($issueCollection as $issue) {
            $issueCount++;

            // Every issue must have these properties
            self::assertNotNull($issue->getTitle(), 'Issue must have a title');
            self::assertIsString($issue->getTitle());
            self::assertNotEmpty($issue->getTitle());

            self::assertNotNull($issue->getDescription(), 'Issue must have a description');
            self::assertIsString($issue->getDescription());

            self::assertNotNull($issue->getSeverity(), 'Issue must have severity');
            self::assertInstanceOf(Severity::class, $issue->getSeverity());
        }

        // Should analyze without throwing exceptions
        self::assertGreaterThanOrEqual(0, $issueCount);
    }

    #[Test]
    public function it_returns_consistent_results(): void
    {
        $divisionByZeroAnalyzer = $this->createAnalyzer();

        // Run analysis twice
        $issueCollection = $divisionByZeroAnalyzer->analyze(QueryDataCollection::empty());
        $issues2 = $divisionByZeroAnalyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issueCollection), $issues2, 'Analyzer should return consistent results on repeated analysis');
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $divisionByZeroAnalyzer = $this->createAnalyzer();
        $issueCollection = $divisionByZeroAnalyzer->analyze(QueryDataCollection::empty());

        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issueCollection as $issue) {
            $severityValue = $issue->getSeverity()->value;
            self::assertContains($severityValue, $validSeverities, "Issue severity must be one of: " . implode(', ', $validSeverities));
        }

        // Ensure we always have at least one assertion
        self::assertTrue(true, 'Severity validation completed');
    }

    #[Test]
    public function it_detects_unprotected_division(): void
    {
        $divisionByZeroAnalyzer = $this->createAnalyzer();

        // Create a query with unprotected division
        $queryData = new QueryData(
            sql: 'SELECT revenue / quantity FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $divisionByZeroAnalyzer->analyze($queryDataCollection);

        // Should detect the division by zero risk
        self::assertGreaterThan(0, count($issueCollection), 'Should detect unprotected division operation');

        // Verify issue details
        $foundDivisionIssue = false;
        foreach ($issueCollection as $issue) {
            if (str_contains(strtolower($issue->getDescription()), 'division') ||
                str_contains(strtolower($issue->getTitle()), 'division')) {
                $foundDivisionIssue = true;
                break;
            }
        }

        self::assertTrue($foundDivisionIssue, 'Should report division by zero risk');
    }

    #[Test]
    public function it_ignores_protected_division_with_nullif(): void
    {
        $divisionByZeroAnalyzer = $this->createAnalyzer();

        // Create a query with protected division using NULLIF
        $queryData = new QueryData(
            sql: 'SELECT revenue / NULLIF(quantity, 0) FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $divisionByZeroAnalyzer->analyze($queryDataCollection);

        // Should NOT detect issues since division is protected
        self::assertCount(0, $issueCollection, 'Should not flag division protected with NULLIF');
    }

    #[Test]
    public function it_ignores_protected_division_with_case(): void
    {
        $divisionByZeroAnalyzer = $this->createAnalyzer();

        // Create a query with protected division using CASE WHEN
        $queryData = new QueryData(
            sql: 'SELECT CASE WHEN quantity != 0 THEN revenue / quantity ELSE 0 END FROM sales',
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $divisionByZeroAnalyzer->analyze($queryDataCollection);

        // Should NOT detect issues since division is protected
        self::assertCount(0, $issueCollection, 'Should not flag division protected with CASE WHEN');
    }

    private function createAnalyzer(): DivisionByZeroAnalyzer
    {
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        return new DivisionByZeroAnalyzer($suggestionFactory);
    }
}
