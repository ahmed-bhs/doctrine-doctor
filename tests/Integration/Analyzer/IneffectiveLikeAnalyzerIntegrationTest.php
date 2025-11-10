<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\IneffectiveLikeAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for IneffectiveLikeAnalyzer.
 *
 * Tests the analyzer's ability to detect LIKE patterns with leading wildcards
 * that prevent index usage and cause full table scans.
 */
final class IneffectiveLikeAnalyzerIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }
    }

    #[Test]
    public function it_detects_like_with_leading_wildcard(): void
    {
        $ineffectiveLikeAnalyzer = $this->createAnalyzer();

        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE email LIKE '%@example.com'",
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect LIKE with leading wildcard');

        foreach ($issueCollection as $issue) {
            $description = strtolower($issue->getDescription());
            self::assertTrue(str_contains($description, 'like') || str_contains($description, 'wildcard') || str_contains($description, 'index'), 'Issue should mention LIKE or wildcard or index problem');
        }
    }

    #[Test]
    public function it_detects_like_with_both_wildcards(): void
    {
        $ineffectiveLikeAnalyzer = $this->createAnalyzer();

        $queryData = new QueryData(
            sql: "SELECT * FROM products WHERE name LIKE '%shoes%'",
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect LIKE with leading wildcard even if trailing wildcard exists');
    }

    #[Test]
    public function it_ignores_like_with_trailing_wildcard_only(): void
    {
        $ineffectiveLikeAnalyzer = $this->createAnalyzer();

        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE email LIKE 'admin@%'",
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should not flag LIKE with trailing wildcard only (can use index)');
    }

    #[Test]
    public function it_ignores_like_without_wildcards(): void
    {
        $ineffectiveLikeAnalyzer = $this->createAnalyzer();

        $queryData = new QueryData(
            sql: "SELECT * FROM users WHERE email LIKE 'admin@example.com'",
            executionTime: QueryExecutionTime::fromMilliseconds(10),
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $ineffectiveLikeAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should not flag LIKE without wildcards');
    }

    private function createAnalyzer(): IneffectiveLikeAnalyzer
    {
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        return new IneffectiveLikeAnalyzer($suggestionFactory);
    }
}
