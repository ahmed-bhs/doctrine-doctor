<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\JoinColumnNonPrimaryKeyAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JoinColumnNonPrimaryKeyAnalyzerTest extends TestCase
{
    #[Test]
    public function it_detects_join_column_referencing_non_primary_key(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/JoinColumnTest',
        ]);

        $analyzer = new JoinColumnNonPrimaryKeyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $queries = QueryDataBuilder::create()->build();
        $issues = $analyzer->analyze($queries);
        $issueArray = $issues->toArray();

        $nonPkIssues = array_filter(
            $issueArray,
            static fn ($issue): bool => str_contains((string) $issue->getDescription(), 'EntityWithNonPkJoin'),
        );

        self::assertNotEmpty($nonPkIssues, 'Should detect join column referencing non-primary key "code"');

        $firstIssue = reset($nonPkIssues);
        self::assertStringContainsString('code', (string) $firstIssue->getDescription());
        self::assertStringContainsString('not part of the primary key', (string) $firstIssue->getDescription());
    }

    #[Test]
    public function it_does_not_flag_join_column_referencing_primary_key(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/JoinColumnTest',
        ]);

        $analyzer = new JoinColumnNonPrimaryKeyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $queries = QueryDataBuilder::create()->build();
        $issues = $analyzer->analyze($queries);
        $issueArray = $issues->toArray();

        $correctJoinIssues = array_filter(
            $issueArray,
            static fn ($issue): bool => str_contains((string) $issue->getDescription(), 'EntityWithCorrectJoin'),
        );

        self::assertEmpty($correctJoinIssues, 'Should not flag join column that correctly references primary key');
    }
}
