<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\NullablePrimaryKeyAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NullablePrimaryKeyAnalyzerTest extends TestCase
{
    private NullablePrimaryKeyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/MappingDeprecationTest',
        ]);

        $this->analyzer = new NullablePrimaryKeyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_a_primary_key_mapped_as_nullable(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'NullableIdEntity'),
        );

        self::assertNotEmpty($relevant, 'Should detect the nullable identifier');
    }

    #[Test]
    public function it_mentions_the_field_name_in_the_title(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'NullableIdEntity'),
        );

        foreach ($relevant as $title) {
            self::assertStringContainsString('code', $title);
        }
    }

    #[Test]
    public function it_does_not_flag_a_non_nullable_primary_key(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'CleanIdEntity'),
        );

        self::assertEmpty($relevant, 'Should not flag a clean identifier or its nullable non-key column');
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
