<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\DiscriminatorColumnAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiscriminatorColumnAnalyzerTest extends TestCase
{
    private DiscriminatorColumnAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/DiscriminatorIntegrityTest',
        ]);

        $this->analyzer = new DiscriminatorColumnAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_discriminator_column_shorter_than_longest_value(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'too short')
                && str_contains($title, 'ShortDiscriminatorVehicle'),
        );

        self::assertNotEmpty($relevant, 'Should detect that length 8 cannot store "articulated_lorry"');
    }

    #[Test]
    public function it_reports_the_required_length_in_the_description(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $relevant = array_filter(
            $issues->toArray(),
            static fn ($issue) => str_contains((string) $issue->getTitle(), 'too short'),
        );

        self::assertNotEmpty($relevant);

        foreach ($relevant as $issue) {
            self::assertStringContainsString('articulated_lorry', $issue->getDescription());
            self::assertStringContainsString('17', $issue->getDescription());
        }
    }

    #[Test]
    public function it_detects_single_table_inheritance_without_index_on_discriminator(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'Unindexed discriminator')
                && str_contains($title, 'ShortDiscriminatorVehicle'),
        );

        self::assertNotEmpty($relevant, 'Should detect the missing index on dtype');
    }

    #[Test]
    public function it_does_not_flag_a_hierarchy_that_is_long_enough_and_indexed(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'IndexedDocument'),
        );

        self::assertEmpty($relevant, 'Should not flag a correctly configured hierarchy');
    }

    #[Test]
    public function it_returns_integrity_issues(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        foreach ($issues->toArray() as $issue) {
            self::assertInstanceOf(IntegrityIssue::class, $issue);
        }
    }

    /**
     * @return list<string>
     */
    private function issueTitles(): array
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        return array_map(
            static fn ($issue) => $issue->getTitle(),
            array_values($issues->toArray()),
        );
    }
}
