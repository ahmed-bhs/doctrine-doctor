<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\DuplicatePrivateFieldInHierarchyAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DuplicatePrivateFieldInHierarchyAnalyzerTest extends TestCase
{
    #[Test]
    public function it_detects_duplicate_private_field_in_hierarchy(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/HierarchyTest',
        ]);

        $analyzer = new DuplicatePrivateFieldInHierarchyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $queries = QueryDataBuilder::create()->build();
        $issues = $analyzer->analyze($queries);
        $issueArray = $issues->toArray();

        $duplicateIssues = array_filter(
            $issueArray,
            static fn ($issue): bool => str_contains((string) $issue->getDescription(), 'ChildWithDuplicateField')
                && str_contains((string) $issue->getDescription(), 'status'),
        );

        self::assertNotEmpty($duplicateIssues, 'Should detect duplicate private field "status" in ChildWithDuplicateField and ParentEntity');
    }

    #[Test]
    public function it_does_not_flag_child_with_unique_field_names(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/HierarchyTest',
        ]);

        $analyzer = new DuplicatePrivateFieldInHierarchyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $queries = QueryDataBuilder::create()->build();
        $issues = $analyzer->analyze($queries);
        $issueArray = $issues->toArray();

        $falsePositives = array_filter(
            $issueArray,
            static fn ($issue): bool => str_contains((string) $issue->getDescription(), 'ChildWithoutDuplicate'),
        );

        self::assertEmpty($falsePositives, 'Should not flag child entity that has unique field names');
    }
}
