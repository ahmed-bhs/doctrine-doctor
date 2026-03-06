<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadePersistOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CascadePersistFalsePositiveTest extends TestCase
{
    private CascadePersistOnIndependentEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FalsePositiveTest',
        ]);

        $this->analyzer = new CascadePersistOnIndependentEntityAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_accounting_entry_as_critical_independent_entity(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $accountingIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains((string) $issue->getDescription(), 'AccountingEntry'),
        );

        self::assertCount(0, $accountingIssues, 'AccountingEntry should not match "Account" pattern -- it is a dependent child entity, not an independent entity like Account/User');
    }
}
