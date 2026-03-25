<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\SingleTableInheritanceNullableColumnAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SingleTableInheritanceNullableColumnAnalyzerTest extends TestCase
{
    private SingleTableInheritanceNullableColumnAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceIntegrityTest',
        ]);

        $this->analyzer = new SingleTableInheritanceNullableColumnAnalyzer($entityManager);
    }

    #[Test]
    public function it_detects_non_nullable_column_on_sti_subclass(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $relevant = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'StiPhone'));

        self::assertNotEmpty($relevant, 'Should detect non-nullable column imei on StiPhone');
    }

    #[Test]
    public function it_does_not_flag_nullable_columns(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $tabletIssues = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'StiTablet'));

        self::assertEmpty($tabletIssues, 'Should not flag nullable columns on StiTablet');
    }

    #[Test]
    public function it_returns_integrity_issues(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        foreach ($issues->toArray() as $issue) {
            self::assertInstanceOf(IntegrityIssue::class, $issue);
        }
    }

    #[Test]
    public function it_mentions_field_name_in_title(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $relevant = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'StiPhone'));

        foreach ($relevant as $issue) {
            self::assertStringContainsString('imei', $issue->getTitle());
        }
    }
}
