<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\SlowQueryAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\Attributes\Test;

final class SlowQueryAnalyzerIntegrationTest extends DatabaseTestCase
{
    private const THRESHOLD_MS = 50;

    private SlowQueryAnalyzer $slowQueryAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();

        $this->slowQueryAnalyzer = new SlowQueryAnalyzer(
            $issueFactory,
            $suggestionFactory,
            self::THRESHOLD_MS,
        );

        $this->createSchema([Product::class, Category::class]);
    }

    #[Test]
    public function it_detects_query_exceeding_threshold(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM products WHERE name LIKE "%expensive%"',
            executionTime: QueryExecutionTime::fromMilliseconds(150),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->slowQueryAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->toArray()[0];
        self::assertStringContainsString('150', (string) $issue->getTitle());
        self::assertStringContainsString('Slow Query', (string) $issue->getTitle());
    }

    #[Test]
    public function it_does_not_flag_fast_queries(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT id, name FROM products WHERE id = 1',
            executionTime: QueryExecutionTime::fromMilliseconds(30),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->slowQueryAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_detects_multiple_slow_queries(): void
    {
        $queries = [
            new QueryData(
                sql: 'SELECT * FROM products ORDER BY name',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [['file' => __FILE__, 'line' => __LINE__]],
            ),
            new QueryData(
                sql: 'SELECT * FROM products WHERE name LIKE "%test%"',
                executionTime: QueryExecutionTime::fromMilliseconds(200),
                params: [],
                backtrace: [['file' => __FILE__, 'line' => __LINE__]],
            ),
            new QueryData(
                sql: 'SELECT * FROM products WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(20),
                params: [],
                backtrace: [['file' => __FILE__, 'line' => __LINE__]],
            ),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->slowQueryAnalyzer->analyze($queryDataCollection);

        self::assertCount(2, $issueCollection);
    }

    #[Test]
    public function it_suggests_index_for_order_by_queries(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM products ORDER BY name',
            executionTime: QueryExecutionTime::fromMilliseconds(120),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->slowQueryAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->toArray()[0];
        $suggestion = $issue->getSuggestion();
        self::assertInstanceOf(SuggestionInterface::class, $suggestion);

        // Verify the suggestion provides optimization advice
        $suggestionText = $suggestion->getDescription();
        self::assertNotEmpty($suggestionText);
        self::assertStringContainsString('Optimize', (string) $suggestionText);
    }

    #[Test]
    public function it_warns_about_leading_wildcard_like(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM products WHERE name LIKE "%search%"',
            executionTime: QueryExecutionTime::fromMilliseconds(180),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->slowQueryAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->toArray()[0];
        $suggestion = $issue->getSuggestion();
        self::assertInstanceOf(SuggestionInterface::class, $suggestion);

        // Verify optimization suggestion is provided
        $suggestionText = $suggestion->getDescription();
        self::assertNotEmpty($suggestionText);
        self::assertStringContainsString('slow query', strtolower((string) $suggestionText));
    }

    #[Test]
    public function it_provides_actionable_suggestions_for_all_issues(): void
    {
        $queries = [
            new QueryData(
                sql: 'SELECT * FROM products ORDER BY name',
                executionTime: QueryExecutionTime::fromMilliseconds(100),
                params: [],
                backtrace: [['file' => __FILE__, 'line' => __LINE__]],
            ),
            new QueryData(
                sql: 'SELECT * FROM products WHERE name LIKE "%search%"',
                executionTime: QueryExecutionTime::fromMilliseconds(150),
                params: [],
                backtrace: [['file' => __FILE__, 'line' => __LINE__]],
            ),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $issueCollection = $this->slowQueryAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection));

        foreach ($issueCollection->toArray() as $issue) {
            self::assertInstanceOf(SuggestionInterface::class, $issue->getSuggestion());
            self::assertNotEmpty($issue->getSuggestion()->getDescription());
        }
    }

    #[Test]
    public function it_respects_custom_threshold(): void
    {
        $customThreshold = 200;
        $slowQueryAnalyzer = new SlowQueryAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $customThreshold,
        );

        $queryData = new QueryData(
            sql: 'SELECT * FROM products',
            executionTime: QueryExecutionTime::fromMilliseconds(150),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $slowQueryAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }
}
