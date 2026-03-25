<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\UniqueEntityWithoutDatabaseIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UniqueEntityWithoutDatabaseIndexAnalyzerTest extends TestCase
{
    #[Test]
    public function it_detects_unique_entity_without_database_index(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/UniqueEntityTest',
        ]);

        $analyzer = new UniqueEntityWithoutDatabaseIndexAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $noIndexIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'UserWithUniqueEntityNoIndex'),
        );

        self::assertNotEmpty($noIndexIssues, 'Should detect #[UniqueEntity] without database index on email');

        $issue = reset($noIndexIssues);
        self::assertStringContainsString('email', $issue->getTitle());
        self::assertStringContainsString('concurrent requests', $issue->getDescription());
        self::assertSame('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_does_not_flag_unique_entity_with_unique_column(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/UniqueEntityTest',
        ]);

        $analyzer = new UniqueEntityWithoutDatabaseIndexAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $uniqueColumnIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'UserWithUniqueEntityAndUniqueColumn'),
        );

        self::assertEmpty($uniqueColumnIssues, 'Should not flag entity with unique: true on column');
    }

    #[Test]
    public function it_does_not_flag_unique_entity_with_unique_constraint(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/UniqueEntityTest',
        ]);

        $analyzer = new UniqueEntityWithoutDatabaseIndexAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $constraintIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'UserWithUniqueEntityAndConstraint'),
        );

        self::assertEmpty($constraintIssues, 'Should not flag entity with #[UniqueConstraint]');
    }

    #[Test]
    public function it_detects_composite_unique_entity_without_index(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/UniqueEntityTest',
        ]);

        $analyzer = new UniqueEntityWithoutDatabaseIndexAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $compositeIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'UserWithCompositeUniqueNoIndex'),
        );

        self::assertNotEmpty($compositeIssues, 'Should detect composite #[UniqueEntity] without database index');
    }

    #[Test]
    public function it_ignores_entities_without_unique_entity_constraint(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/UniqueEntityTest',
        ]);

        $analyzer = new UniqueEntityWithoutDatabaseIndexAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $noConstraintIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'EntityWithoutUniqueEntity'),
        );

        self::assertEmpty($noConstraintIssues, 'Should not flag entities without #[UniqueEntity]');
    }
}
