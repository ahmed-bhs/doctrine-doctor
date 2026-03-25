<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MappedSuperclassAsTargetEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MappedSuperclassAsTargetEntityAnalyzerTest extends TestCase
{
    private MappedSuperclassAsTargetEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceIntegrityTest',
        ]);

        $this->analyzer = new MappedSuperclassAsTargetEntityAnalyzer($entityManager);
    }

    #[Test]
    public function it_detects_association_targeting_mapped_superclass(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $relevant = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Mapped Superclass'));

        self::assertNotEmpty($relevant, 'Should detect association targeting a Mapped Superclass');
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
    public function it_mentions_target_class_in_description(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $relevant = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'Mapped Superclass'));

        foreach ($relevant as $issue) {
            self::assertStringContainsString('AbstractPerson', $issue->getDescription());
        }
    }

    #[Test]
    public function it_does_not_flag_normal_associations(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceTest',
        ]);

        $analyzer = new MappedSuperclassAsTargetEntityAnalyzer($entityManager);
        $issues = $analyzer->analyze(QueryDataBuilder::create()->build());

        $relevant = array_filter($issues->toArray(), static fn ($issue) => str_contains($issue->getTitle(), 'Mapped Superclass'));

        self::assertEmpty($relevant, 'Should not flag hierarchies without mapped superclass targets');
    }
}
