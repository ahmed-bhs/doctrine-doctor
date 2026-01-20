<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\EntityManagerInEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for EntityManagerInEntityAnalyzer.
 */
final class EntityManagerInEntityAnalyzerTest extends TestCase
{
    private EntityManagerInEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new EntityManagerInEntityAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_entity_manager_in_constructor(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        $constructorIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'constructor'),
        );

        self::assertGreaterThan(0, count($constructorIssues));
    }

    #[Test]
    public function it_detects_entity_manager_property(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));
    }

    #[Test]
    public function it_detects_entity_manager_usage_in_methods(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        $usageIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getDescription(), 'flush') ||
                          str_contains($issue->getDescription(), 'persist'),
        );

        self::assertGreaterThan(0, count($usageIssues));
    }

    #[Test]
    public function it_has_critical_severity(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        if (count($issuesArray) > 0) {
            self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
        }
    }

    #[Test]
    public function it_explains_anti_pattern(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        if (count($issuesArray) > 0) {
            $description = $issuesArray[0]->getDescription();
            self::assertTrue(
                str_contains($description, 'domain') ||
                str_contains($description, 'persistence') ||
                str_contains($description, 'separation'),
            );
        }
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        if (count($issuesArray) > 0) {
            $suggestion = $issuesArray[0]->getSuggestion();
            self::assertNotNull($suggestion);
        }
    }

    #[Test]
    public function it_includes_backtrace(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        if (count($issuesArray) > 0) {
            $backtrace = $issuesArray[0]->getBacktrace();
            self::assertNotNull($backtrace);
        }
    }

    #[Test]
    public function it_handles_exceptions_gracefully(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        self::assertIsObject($issues);
        self::assertIsArray($issues->toArray());
    }
}
