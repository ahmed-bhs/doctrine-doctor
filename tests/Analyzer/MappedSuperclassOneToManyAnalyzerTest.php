<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MappedSuperclassOneToManyAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MappedSuperclassOneToManyAnalyzerTest extends TestCase
{
    private MappedSuperclassOneToManyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceIntegrityTest',
        ]);

        $this->analyzer = new MappedSuperclassOneToManyAnalyzer($entityManager);
    }

    #[Test]
    public function it_detects_one_to_many_on_mapped_superclass(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $relevant = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'OneToMany'));

        self::assertNotEmpty($relevant, 'Should detect OneToMany on Mapped Superclass');
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
    public function it_mentions_field_in_title(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());
        $issuesArray = $issues->toArray();

        $relevant = array_filter($issuesArray, static fn ($issue) => str_contains($issue->getTitle(), 'AbstractPerson'));

        self::assertNotEmpty($relevant);
        foreach ($relevant as $issue) {
            self::assertStringContainsString('logs', $issue->getTitle());
        }
    }

    #[Test]
    public function it_does_not_flag_regular_entities(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/InheritanceTest',
        ]);

        $analyzer = new MappedSuperclassOneToManyAnalyzer($entityManager);
        $issues = $analyzer->analyze(QueryDataBuilder::create()->build());

        self::assertEmpty($issues->toArray(), 'Should not flag entities without mapped superclass OneToMany');
    }
}
