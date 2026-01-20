<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\NullComparisonAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for NullComparisonAnalyzer.
 *
 * Tests the analyzer's ability to detect incorrect NULL comparisons
 * using = or != operators instead of IS NULL / IS NOT NULL.
 */
final class NullComparisonAnalyzerIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }
    }

    #[Test]
    public function it_detects_equals_null_comparison(): void
    {
        $nullComparisonAnalyzer = $this->createAnalyzer();

        $queryData = new QueryData(
            sql: 'SELECT * FROM users WHERE bonus = NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect incorrect = NULL comparison');

        foreach ($issueCollection as $issue) {
            $description = strtolower($issue->getDescription());
            self::assertTrue(str_contains($description, 'null') || str_contains($description, 'is null'), 'Issue should mention NULL comparison');
        }
    }

    #[Test]
    public function it_detects_not_equals_null_comparison(): void
    {
        $nullComparisonAnalyzer = $this->createAnalyzer();

        $queryData = new QueryData(
            sql: 'SELECT * FROM users WHERE bonus != NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect incorrect != NULL comparison');
    }

    #[Test]
    public function it_ignores_correct_is_null_usage(): void
    {
        $nullComparisonAnalyzer = $this->createAnalyzer();

        $queryData = new QueryData(
            sql: 'SELECT * FROM users WHERE bonus IS NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should not flag correct IS NULL usage');
    }

    #[Test]
    public function it_ignores_correct_is_not_null_usage(): void
    {
        $nullComparisonAnalyzer = $this->createAnalyzer();

        $queryData = new QueryData(
            sql: 'SELECT * FROM users WHERE bonus IS NOT NULL',
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $nullComparisonAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should not flag correct IS NOT NULL usage');
    }

    private function createAnalyzer(): NullComparisonAnalyzer
    {
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        return new NullComparisonAnalyzer($suggestionFactory);
    }
}
