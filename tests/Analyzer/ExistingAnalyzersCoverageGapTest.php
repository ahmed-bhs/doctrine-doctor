<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\JoinOptimizationAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Documents current coverage gaps when only existing tagged analyzers are used.
 */
final class ExistingAnalyzersCoverageGapTest extends TestCase
{
    #[Test]
    public function n_plus_one_analyzer_does_not_emit_nested_n_plus_one_issue_type(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $analyzer = new NPlusOneAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            3,
        );

        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM articles', 0.010)
            ->addQuery('SELECT * FROM users WHERE id = 1', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 2', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 3', 0.005)
            ->addQuery('SELECT * FROM countries WHERE id = 10', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 11', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 12', 0.003)
            ->build();

        $issues = $analyzer->analyze($queries)->toArray();

        self::assertGreaterThanOrEqual(1, \count($issues), 'Existing N+1 analyzer should detect repeated patterns');

        $types = array_map(static fn ($issue): string => $issue->getType(), $issues);

        self::assertNotContains('nested_n_plus_one', $types);
        self::assertContains('n_plus_one', $types);
    }

    #[Test]
    public function join_optimization_analyzer_does_not_emit_unused_eager_load_issue_type(): void
    {
        $sqlExtractor = new SqlStructureExtractor();
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();
        $collectionJoinDetector = new CollectionJoinDetector($entityManager, $sqlExtractor);

        $analyzer = new JoinOptimizationAnalyzer(
            $collectionJoinDetector,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $sqlExtractor,
            5,
            8,
        );

        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT a.id FROM article a LEFT JOIN user u ON u.id = a.author_id')
            ->build();

        $issues = $analyzer->analyze($queries)->toArray();

        self::assertGreaterThanOrEqual(1, \count($issues), 'Existing JOIN analyzer should flag the unused JOIN');

        $types = array_map(static fn ($issue): string => $issue->getType(), $issues);
        $titles = array_map(static fn ($issue): string => (string) $issue->getTitle(), $issues);

        self::assertNotContains('unused_eager_load', $types);
        self::assertTrue(
            [] !== array_filter($titles, static fn (string $title): bool => str_contains($title, 'Unused JOIN')),
            'Expected at least one "Unused JOIN" issue from existing analyzer',
        );
    }
}
