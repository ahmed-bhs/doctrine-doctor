<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\StringDefaultExpressionAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StringDefaultExpressionAnalyzerTest extends TestCase
{
    private StringDefaultExpressionAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/MappingDeprecationTest',
        ]);

        $this->analyzer = new StringDefaultExpressionAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_a_string_default_on_a_datetime_column(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'createdAt'),
        );

        self::assertNotEmpty($relevant, 'Should detect CURRENT_TIMESTAMP on createdAt');
    }

    #[Test]
    public function it_names_the_replacement_expression_in_the_description(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $relevant = array_filter(
            $issues->toArray(),
            static fn ($issue) => str_contains((string) $issue->getTitle(), 'createdAt'),
        );

        self::assertNotEmpty($relevant);

        foreach ($relevant as $issue) {
            self::assertStringContainsString('CurrentTimestamp', $issue->getDescription());
        }
    }

    #[Test]
    public function it_does_not_flag_a_string_column_holding_the_same_literal(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'notATemporalColumn'),
        );

        self::assertEmpty($relevant, 'Only temporal columns are deprecated by ORM 3.6');
    }

    #[Test]
    public function it_does_not_flag_a_temporal_column_without_default(): void
    {
        $titles = $this->issueTitles();

        $relevant = array_filter(
            $titles,
            static fn (string $title) => str_contains($title, 'updatedAt'),
        );

        self::assertEmpty($relevant);
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
