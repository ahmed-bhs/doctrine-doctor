<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\ClassTableInheritanceDepthAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClassTableInheritanceDepthAnalyzerTest extends TestCase
{
    private ClassTableInheritanceDepthAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceTest',
        ]);

        $this->analyzer = new ClassTableInheritanceDepthAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            3,
        );
    }

    #[Test]
    public function it_detects_deep_cti_hierarchy(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $deepIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Deep CTI'));

        self::assertNotEmpty($deepIssues, 'Should detect deep CTI hierarchy (CtiRectangle is 3 levels deep)');
    }

    #[Test]
    public function it_detects_rectangle_as_deep(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $rectangleIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'CtiRectangle'));

        self::assertNotEmpty($rectangleIssues, 'CtiRectangle (depth 3) should be flagged');
    }

    #[Test]
    public function it_does_not_flag_shallow_hierarchies(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $carIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'CtiCar'));

        self::assertEmpty($carIssues, 'CtiCar (depth 1) should not be flagged');
    }

    #[Test]
    public function it_returns_integrity_issues(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $deepIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Deep CTI'));

        foreach ($deepIssues as $issue) {
            self::assertInstanceOf(IntegrityIssue::class, $issue);
        }
    }

    #[Test]
    public function it_assigns_critical_severity_for_very_deep_hierarchy(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceTest',
        ]);

        $analyzer = new ClassTableInheritanceDepthAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            2,
        );

        $issues = $analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $rectangleIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'CtiRectangle'));

        self::assertNotEmpty($rectangleIssues);
        $issue = reset($rectangleIssues);
        self::assertEquals('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_provides_suggestion_with_chain(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $deepIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Deep CTI'));

        foreach ($deepIssues as $issue) {
            self::assertNotNull($issue->getSuggestion());
        }
    }

    #[Test]
    public function it_mentions_join_count_in_description(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $deepIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Deep CTI'));

        foreach ($deepIssues as $issue) {
            self::assertStringContainsString('JOIN', $issue->getDescription());
        }
    }
}
