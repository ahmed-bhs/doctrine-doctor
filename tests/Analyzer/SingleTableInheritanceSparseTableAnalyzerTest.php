<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\SingleTableInheritanceSparseTableAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SingleTableInheritanceSparseTableAnalyzerTest extends TestCase
{
    private SingleTableInheritanceSparseTableAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceTest',
        ]);

        $this->analyzer = new SingleTableInheritanceSparseTableAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            0.5,
        );
    }

    #[Test]
    public function it_detects_sparse_sti_hierarchy(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $stiIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Sparse STI'));

        self::assertNotEmpty($stiIssues, 'Should detect sparse STI hierarchy');
    }

    #[Test]
    public function it_returns_integrity_issues(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            self::assertInstanceOf(IntegrityIssue::class, $issue);
        }
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $stiIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Sparse STI'));

        foreach ($stiIssues as $issue) {
            self::assertNotNull($issue->getSuggestion());
        }
    }

    #[Test]
    public function it_mentions_root_entity_in_description(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $stiIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Sparse STI'));

        foreach ($stiIssues as $issue) {
            self::assertStringContainsString('StiAnimal', $issue->getDescription());
        }
    }

    #[Test]
    public function it_does_not_flag_with_high_threshold(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceTest',
        ]);

        $analyzer = new SingleTableInheritanceSparseTableAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            0.99,
        );

        $issues = $analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $stiIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Sparse STI'));

        self::assertEmpty($stiIssues, 'Should not flag with very high threshold');
    }
}
