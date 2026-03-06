<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NPlusOneAnalyzerFalsePositiveTest extends TestCase
{
    private NPlusOneAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity',
        ]);

        $this->analyzer = new NPlusOneAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            5,
        );
    }

    #[Test]
    public function it_falsely_flags_identical_queries_from_different_contexts_as_n_plus_one(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 5; ++$i) {
            $builder->addQuery('SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?', 0.5);
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues), 'Known false positive: identical queries from independent contexts (e.g. sidebar widget + main content) are grouped as N+1');
    }

    #[Test]
    public function it_falsely_flags_doctrine_batch_loader_decomposed_queries(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 6; ++$i) {
            $builder->addQuery(
                'SELECT t0_.id AS id_0, t0_.title AS title_1 FROM articles t0_ WHERE t0_.category_id = ?',
                0.3,
            );
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues), 'Known false positive: Doctrine batch entity loader decomposes IN clauses into individual WHERE queries that look like N+1');
    }

    #[Test]
    public function it_falsely_flags_queries_with_different_backtraces_as_n_plus_one(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?',
            [['file' => '/app/src/Controller/DashboardController.php', 'line' => 42, 'function' => 'getAuthor']],
            0.5,
        );
        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?',
            [['file' => '/app/src/Controller/DashboardController.php', 'line' => 42, 'function' => 'getAuthor']],
            0.5,
        );
        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?',
            [['file' => '/app/src/Twig/SidebarExtension.php', 'line' => 15, 'function' => 'getEditor']],
            0.5,
        );
        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?',
            [['file' => '/app/src/Twig/SidebarExtension.php', 'line' => 15, 'function' => 'getEditor']],
            0.5,
        );
        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?',
            [['file' => '/app/src/EventListener/AuditListener.php', 'line' => 88, 'function' => 'getUser']],
            0.5,
        );

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Fixed: queries from different backtrace origins are grouped separately and no single group reaches the threshold');
    }
}
