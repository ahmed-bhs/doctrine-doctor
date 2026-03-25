<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\InheritanceTypeOnNonRootEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InheritanceTypeOnNonRootEntityAnalyzerTest extends TestCase
{
    private InheritanceTypeOnNonRootEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceIntegrityTest',
        ]);

        $this->analyzer = new InheritanceTypeOnNonRootEntityAnalyzer($entityManager);
    }

    #[Test]
    public function it_detects_inheritance_type_on_non_root_entity(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $relevant = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'non-root'));

        self::assertNotEmpty($relevant, 'Should detect #[InheritanceType] on non-root entity');
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
    public function it_mentions_both_child_and_root_in_title(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $relevant = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'CtiBus'));

        self::assertNotEmpty($relevant);
        foreach ($relevant as $issue) {
            self::assertStringContainsString('CtiTransport', $issue->getTitle());
        }
    }

    #[Test]
    public function it_does_not_flag_root_entities(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $flaggedRoots = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'CtiTransport') && !str_contains($issue->getTitle(), 'CtiBus'));

        self::assertEmpty($flaggedRoots, 'Should not flag root entities that declare #[InheritanceType]');
    }
}
