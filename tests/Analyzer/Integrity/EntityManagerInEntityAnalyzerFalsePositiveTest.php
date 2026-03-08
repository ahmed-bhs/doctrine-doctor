<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\EntityManagerInEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityManagerInEntityAnalyzerFalsePositiveTest extends TestCase
{
    private EntityManagerInEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FalsePositiveTest',
        ]);

        $this->analyzer = new EntityManagerInEntityAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            new IssueFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_property_named_manager_without_em_type(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $propertyIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'property')
                && str_contains($issue->getDescription(), 'manager'),
        );

        self::assertCount(0, $propertyIssues, 'Should not flag a property named $manager when its type is not EntityManager');
    }
}
