<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\MissingIndexIssue;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PostgreSQLSchemaManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MissingIndexAnalyzerPostgreSQLFilterTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: int, 3?: array<string, mixed>}> */
    public static function sequential_scan_filters(): iterable
    {
        yield 'unindexed column' => ["email = 'alice'", "(email = 'alice'::text)", 1];
        yield 'indexed column in literal only' => ["email = 'name'", "(email = 'name'::text)", 1];
        yield 'function on indexed column' => ["LOWER(name) = 'alice'", "(lower(name) = 'alice'::text)", 1];
        yield 'usable index on filtered column' => ["name = 'alice'", "(name = 'alice'::text)", 0];
        yield 'cast of indexed column' => ["name::integer = 42", "((name)::integer = 42)", 1];
        yield 'unindexed alternative' => ["name = 'alice' OR email = 'bob'", "((name = 'alice'::text) OR (email = 'bob'::text))", 1];
        yield 'partial index without its predicate' => ["name = 'alice'", "(name = 'alice'::text)", 1, ['where' => 'active = true']];
        yield 'indexed range after another predicate' => ["email = 'alice' AND name >= 'a'", "((email = 'alice'::text) AND (name >= 'a'::text))", 0];
        yield 'quoted column' => ['"name" = \'alice\'', '("name" = \'alice\'::text)', 0];
        yield 'predicate text in literal' => ["email = 'x AND name = alice'", "(email = 'x AND name = alice'::text)", 1];
    }

    /** @param array<string, mixed> $indexOptions */
    #[Test]
    #[DataProvider('sequential_scan_filters')]
    public function it_reports_scans_unless_an_index_can_serve_the_filter(string $predicate, string $filter, int $expectedIssues, array $indexOptions = []): void
    {
        $sql = 'SELECT * FROM users WHERE ' . $predicate;
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['QUERY PLAN' => 'Seq Scan on users  (cost=0.00..250.00 rows=2000 width=64)'],
            ['QUERY PLAN' => '  Filter: ' . $filter],
        ]);

        // Replay PostgreSQL's EXPLAIN and index catalog through the public analyzer API.
        // Only the database boundary is mocked; parsing and issue creation are real.
        $schemaManager = $this->createMock(PostgreSQLSchemaManager::class);
        $schemaManager->method('listTableIndexes')->with('users')->willReturn([
            'idx_name' => new Index('idx_name', ['name'], options: $indexOptions),
        ]);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects(self::once())->method('executeQuery')->with('EXPLAIN ' . $sql, [])->willReturn($result);

        $analyzer = new MissingIndexAnalyzer(
            new SuggestionFactory(new InMemoryTemplateRenderer()),
            $connection,
        );
        $queries = QueryDataBuilder::create()->addQuery($sql, 100.0)->build();

        $issues = $analyzer->analyze($queries)->toArray();

        self::assertCount($expectedIssues, $issues, 'An unrelated or unusable index must not suppress the missing-index issue.');
        if (1 === $expectedIssues) {
            self::assertInstanceOf(MissingIndexIssue::class, $issues[0]);
        }
    }
}
