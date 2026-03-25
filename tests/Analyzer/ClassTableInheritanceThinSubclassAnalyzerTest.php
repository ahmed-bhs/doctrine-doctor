<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\ClassTableInheritanceThinSubclassAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClassTableInheritanceThinSubclassAnalyzerTest extends TestCase
{
    private ClassTableInheritanceThinSubclassAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceTest',
        ]);

        $this->analyzer = new ClassTableInheritanceThinSubclassAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            2,
        );
    }

    #[Test]
    public function it_detects_thin_cti_subclasses(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $thinIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Thin CTI'));

        self::assertNotEmpty($thinIssues, 'Should detect thin CTI subclasses (CtiCar and CtiBike add only 1 field each)');
    }

    #[Test]
    public function it_detects_car_and_bike_as_thin(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $titles = array_map(static fn ($issue) => $issue->getTitle(), $issuesArray);
        $thinTitles = array_filter($titles, static fn ($t) => str_contains($t, 'Thin CTI'));

        $hasCar = array_filter($thinTitles, static fn ($t) => str_contains($t, 'CtiCar'));
        $hasBike = array_filter($thinTitles, static fn ($t) => str_contains($t, 'CtiBike'));

        self::assertNotEmpty($hasCar, 'CtiCar adds only 1 field, should be flagged');
        self::assertNotEmpty($hasBike, 'CtiBike adds only 1 field, should be flagged');
    }

    #[Test]
    public function it_returns_integrity_issues_with_info_severity(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $thinIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Thin CTI'));

        foreach ($thinIssues as $issue) {
            self::assertInstanceOf(IntegrityIssue::class, $issue);
            self::assertEquals('info', $issue->getSeverity()->value);
        }
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $thinIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Thin CTI'));

        foreach ($thinIssues as $issue) {
            self::assertNotNull($issue->getSuggestion());
        }
    }

    #[Test]
    public function it_does_not_flag_with_zero_threshold(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceTest',
        ]);

        $analyzer = new ClassTableInheritanceThinSubclassAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            0,
        );

        $issues = $analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $thinIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Thin CTI Subclass: CtiCar'));

        self::assertEmpty($thinIssues, 'Should not flag when threshold is 0');
    }
}
