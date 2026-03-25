<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\DenormalizedAggregateWithoutLockingAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DenormalizedAggregateWithoutLockingAnalyzerTest extends TestCase
{
    #[Test]
    public function it_detects_aggregate_field_mutation_without_locking(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/DenormalizedAggregateTest',
        ]);

        $analyzer = new DenormalizedAggregateWithoutLockingAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $accountIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'AccountWithoutLocking'),
        );

        self::assertNotEmpty($accountIssues, 'Should detect aggregate mutation without locking on AccountWithoutLocking');

        $issue = reset($accountIssues);
        self::assertStringContainsString('addEntry', $issue->getTitle());
        self::assertStringContainsString('race condition', $issue->getDescription());
        self::assertSame('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_does_not_flag_entity_with_version_field(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/DenormalizedAggregateTest',
        ]);

        $analyzer = new DenormalizedAggregateWithoutLockingAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $versionedIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'AccountWithVersion'),
        );

        self::assertEmpty($versionedIssues, 'Should not flag entity with #[ORM\Version]');
    }

    #[Test]
    public function it_ignores_entities_without_aggregate_pattern(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/DenormalizedAggregateTest',
        ]);

        $analyzer = new DenormalizedAggregateWithoutLockingAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $noAggregateIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'EntityWithoutAggregate'),
        );

        self::assertEmpty($noAggregateIssues, 'Should not flag entities without aggregate mutation pattern');
    }

    #[Test]
    public function it_ignores_entry_entities(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/DenormalizedAggregateTest',
        ]);

        $analyzer = new DenormalizedAggregateWithoutLockingAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $entryIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'Entry')
                && !str_contains($issue->getTitle(), 'addEntry'),
        );

        self::assertEmpty($entryIssues, 'Should not flag Entry entities that have no aggregate pattern');
    }
}
