<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\BulkInsertAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ORM writes one INSERT per persisted entity. For an import of hundreds of
 * rows, a multi-row INSERT through DBAL does the same work in a few statements,
 * at the cost of what the unit of work provides: lifecycle callbacks, entity
 * listeners and generated identifiers.
 */
final class BulkInsertAnalyzerTest extends TestCase
{
    #[Test]
    public function it_reports_repeated_single_row_inserts_into_one_table(): void
    {
        $issues = $this->analyze($this->inserts('INSERT INTO users (name, email) VALUES (?, ?)', 100));

        self::assertCount(1, $issues);
        self::assertSame('bulk_insert', $issues[0]->getType());
        self::assertStringContainsString('100', $issues[0]->getTitle());
        self::assertStringContainsString('users', $issues[0]->getTitle());
        self::assertNotNull($issues[0]->getSuggestion());
    }

    #[Test]
    public function it_stays_silent_below_the_threshold(): void
    {
        self::assertCount(0, $this->analyze($this->inserts('INSERT INTO users (name, email) VALUES (?, ?)', 99)));
    }

    #[Test]
    public function it_counts_each_table_separately(): void
    {
        $queries = QueryDataBuilder::create();
        for ($i = 0; $i < 60; ++$i) {
            $queries->addQuery('INSERT INTO users (name, email) VALUES (?, ?)');
            $queries->addQuery('INSERT INTO orders (user_id) VALUES (?)');
        }

        self::assertCount(0, $this->analyze($queries->build()));
    }

    #[Test]
    public function it_ignores_inserts_that_are_already_batched(): void
    {
        self::assertCount(0, $this->analyze($this->inserts('INSERT INTO users (name, email) VALUES (?, ?), (?, ?), (?, ?)', 100)));
        self::assertCount(0, $this->analyze($this->inserts('INSERT INTO users_archive (name, email) SELECT name, email FROM users', 100)));
    }

    #[Test]
    public function it_warns_about_what_dbal_bypasses_for_a_mapped_entity(): void
    {
        $issues = $this->analyze($this->inserts('INSERT INTO EntityWithSafeCallback (createdAt) VALUES (?)', 100));

        self::assertCount(1, $issues);
        self::assertStringContainsString('EntityWithSafeCallback', $issues[0]->getDescription());
        self::assertStringContainsString('prePersist', $issues[0]->getDescription());
        self::assertStringContainsString('generated identifiers', $issues[0]->getDescription());
    }

    #[Test]
    public function it_works_without_an_entity_manager(): void
    {
        $analyzer = new BulkInsertAnalyzer(PlatformAnalyzerTestHelper::createSuggestionFactory());

        self::assertCount(1, $analyzer->analyze($this->inserts('INSERT INTO users (name, email) VALUES (?, ?)', 100)));
    }

    private function inserts(string $sql, int $count): QueryDataCollection
    {
        $queries = QueryDataBuilder::create();
        for ($i = 0; $i < $count; ++$i) {
            $queries->addQuery($sql);
        }

        return $queries->build();
    }

    /**
     * @return list<IssueInterface>
     */
    private function analyze(QueryDataCollection $queries): array
    {
        $analyzer = new BulkInsertAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createTestEntityManager(),
        );

        return array_values($analyzer->analyze($queries)->toArray());
    }
}
