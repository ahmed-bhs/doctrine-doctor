<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingEmbeddableOpportunityAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MissingEmbeddableOpportunityAnalyzerIntegrationTest extends TestCase
{
    #[Test]
    public function it_does_not_report_pattern_found_in_single_entity(): void
    {
        $analyzer = $this->createAnalyzerForFixtures(
            [__DIR__ . '/../../Fixtures/Entity/EmbeddableTest'],
        );

        $issues = $analyzer->analyze(QueryDataCollection::empty())->toArray();

        $personNameIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'PersonName'),
        );

        self::assertCount(0, $personNameIssues);
    }

    #[Test]
    public function it_reports_pattern_found_in_multiple_entities(): void
    {
        $analyzer = $this->createAnalyzerForFixtures(
            [__DIR__ . '/../../Fixtures/Entity/EmbeddableTest'],
        );

        $issues = $analyzer->analyze(QueryDataCollection::empty())->toArray();

        $addressIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Address'),
        );

        self::assertCount(2, $addressIssues);
    }

    #[Test]
    public function it_does_not_suggest_a_value_object_to_the_entity_named_after_it_or_for_one_field(): void
    {
        $analyzer = $this->createAnalyzerForFixtures([__DIR__ . '/../../Fixtures/Entity/EmbeddableNoiseTest'], 1);

        $titles = array_map(static fn ($issue): string => (string) $issue->getTitle(), $analyzer->analyze(QueryDataCollection::empty())->toArray());

        self::assertSame(['Missing Address Embeddable: Supplier'], array_values($titles));
    }

    #[Test]
    public function it_reports_single_entity_when_min_entities_is_one(): void
    {
        $analyzer = $this->createAnalyzerForFixtures(
            [__DIR__ . '/../../Fixtures/Entity/EmbeddableTest'],
            minEntities: 1,
        );

        $issues = $analyzer->analyze(QueryDataCollection::empty())->toArray();

        $personNameIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'PersonName'),
        );

        self::assertCount(1, $personNameIssues);
    }

    #[Test]
    public function it_analyzes_without_errors(): void
    {
        $analyzer = $this->createAnalyzerForFixtures(
            [__DIR__ . '/../../Fixtures/Entity/EmbeddableTest'],
        );

        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issueCollection);
    }

    #[Test]
    public function it_returns_consistent_results(): void
    {
        $analyzer = $this->createAnalyzerForFixtures(
            [__DIR__ . '/../../Fixtures/Entity/EmbeddableTest'],
        );

        $issues1 = $analyzer->analyze(QueryDataCollection::empty());
        $issues2 = $analyzer->analyze(QueryDataCollection::empty());

        self::assertCount(count($issues1), $issues2);
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $analyzer = $this->createAnalyzerForFixtures(
            [__DIR__ . '/../../Fixtures/Entity/EmbeddableTest'],
        );

        $issues = $analyzer->analyze(QueryDataCollection::empty());
        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issues as $issue) {
            self::assertInstanceOf(Severity::class, $issue->getSeverity());
            self::assertContains($issue->getSeverity()->value, $validSeverities);
        }
    }

    /**
     * @param array<string> $entityPaths
     */
    private function createAnalyzerForFixtures(array $entityPaths, int $minEntities = 2): MissingEmbeddableOpportunityAnalyzer
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager($entityPaths);

        return new MissingEmbeddableOpportunityAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $minEntities,
        );
    }
}
