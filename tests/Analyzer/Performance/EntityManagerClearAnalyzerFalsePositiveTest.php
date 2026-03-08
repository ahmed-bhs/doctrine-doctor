<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\EntityManagerClearAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityManagerClearAnalyzerFalsePositiveTest extends TestCase
{
    private EntityManagerClearAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new EntityManagerClearAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_database_migration_as_missing_clear(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 25; ++$i) {
            $builder->addQuery(
                sprintf('INSERT INTO migration_versions (version) VALUES (\'Version%d\')', $i),
                0.01,
            );
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $clearIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Memory Leak'),
        );

        self::assertCount(0, $clearIssues, 'Migration table INSERTs should not be flagged as missing EntityManager::clear()');
    }

    #[Test]
    public function it_falsely_flags_interleaved_queries_due_to_permissive_gap_tolerance(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 25; ++$i) {
            $builder->addQuery(
                sprintf('INSERT INTO audit_log (message) VALUES (\'Event %d\')', $i),
                0.01,
            );

            for ($j = 0; $j < 8; ++$j) {
                $builder->addQuery('SELECT COUNT(*) FROM notifications', 0.01);
            }
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $clearIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Memory Leak'),
        );

        self::assertCount(0, $clearIssues, 'INSERTs with 8 unrelated queries between them should not be considered sequential with maxGap=3');
    }
}
