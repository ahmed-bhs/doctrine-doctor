<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\EagerLoadingMappingAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EagerLoadingMappingAnalyzer.
 * Detects fetch: 'EAGER' declared in entity mapping attributes.
 */
final class EagerLoadingMappingAnalyzerTest extends TestCase
{
    private \Doctrine\ORM\EntityManager $entityManager;

    private EagerLoadingMappingAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/EagerFetchTest',
        ]);

        $this->analyzer = new EagerLoadingMappingAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_detects_eager_fetch_on_many_to_one(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $eagerIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'EntityWithEagerFetch')
                && str_contains((string) $issue->getTitle(), '$category'),
        );

        self::assertNotEmpty($eagerIssues, 'Should detect fetch:EAGER on ManyToOne');

        $issue = reset($eagerIssues);
        self::assertEquals(Severity::INFO, $issue->getSeverity());
        self::assertStringContainsString('EAGER', $issue->getDescription());
        self::assertStringContainsString('QueryBuilder', $issue->getDescription());
    }

    #[Test]
    public function it_detects_eager_fetch_on_one_to_many(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $eagerIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'EntityWithEagerFetch')
                && str_contains((string) $issue->getTitle(), '$items'),
        );

        self::assertNotEmpty($eagerIssues, 'Should detect fetch:EAGER on OneToMany');

        $issue = reset($eagerIssues);
        self::assertEquals(Severity::INFO, $issue->getSeverity());
    }

    #[Test]
    public function it_does_not_flag_lazy_fetch(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $lazyIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'EntityWithLazyFetch'),
        );

        self::assertEmpty($lazyIssues, 'Should not flag lazy (default) fetch');
    }

    #[Test]
    public function it_does_not_flag_default_fetch(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $lazyIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'EntityWithLazyFetch')
                && str_contains((string) $issue->getTitle(), '$parent'),
        );

        self::assertEmpty($lazyIssues, 'Should not flag default lazy loading');
    }

    #[Test]
    public function it_returns_info_severity(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $eagerIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Global eager fetch'),
        );

        self::assertNotEmpty($eagerIssues);

        foreach ($eagerIssues as $issue) {
            self::assertEquals(Severity::INFO, $issue->getSeverity());
        }
    }

    #[Test]
    public function it_provides_suggestion_with_querybuilder_alternative(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $eagerIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Global eager fetch'),
        );

        self::assertGreaterThan(0, count($eagerIssues));

        foreach ($eagerIssues as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Every issue should have a suggestion');

            $code = $suggestion->getCode();
            self::assertTrue(
                str_contains($code, 'leftJoin') || str_contains($code, 'addSelect'),
                'Suggestion should mention QueryBuilder alternatives',
            );
        }
    }

    #[Test]
    public function it_includes_entity_and_field_in_title(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $eagerIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Global eager fetch'),
        );

        self::assertNotEmpty($eagerIssues);

        foreach ($eagerIssues as $issue) {
            $title = $issue->getTitle();

            self::assertStringContainsString('EntityWithEagerFetch', $title);
            self::assertTrue(
                str_contains($title, '$category') || str_contains($title, '$items'),
                'Title should include field name',
            );
        }
    }

    #[Test]
    public function it_handles_empty_metadata_gracefully(): void
    {
        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([__DIR__ . '/../../Fixtures/NonExistentPath']);

        $connection = \Doctrine\DBAL\DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $emptyEm = new \Doctrine\ORM\EntityManager($connection, $configuration);
        $analyzer = new EagerLoadingMappingAnalyzer(
            $emptyEm,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $queries = QueryDataBuilder::create()->build();
        $issues = $analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_total_two_eager_fetch_issues(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $eagerIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Global eager fetch'),
        );

        self::assertCount(2, $eagerIssues, 'Should detect exactly 2 eager fetch issues (category and items)');
    }

    #[Test]
    public function it_includes_target_entity_info_in_description(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $eagerIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Global eager fetch'),
        );

        self::assertNotEmpty($eagerIssues);

        foreach ($eagerIssues as $issue) {
            $description = $issue->getDescription();

            self::assertNotEmpty($description);
            self::assertStringContainsString('Remove', $description);
        }
    }

    #[Test]
    public function it_provides_migration_guidance_in_suggestion(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $eagerIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Global eager fetch'),
        );

        self::assertGreaterThan(0, count($eagerIssues), 'Should have eager fetch issues');

        foreach ($eagerIssues as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion);

            $code = $suggestion->getCode();
            self::assertStringContainsString('BEFORE', $code);
            self::assertStringContainsString('AFTER', $code);
            self::assertStringContainsString('Remove', $code);
        }
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }
}
