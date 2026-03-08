<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FloatForMoneyAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FloatForMoneyAnalyzerFalsePositiveTest extends TestCase
{
    private FloatForMoneyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FalsePositiveTest',
        ]);

        $this->analyzer = new FloatForMoneyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_total_count_as_monetary_field(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $totalCountIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains((string) $issue->getDescription(), 'totalCount'),
        );

        self::assertCount(0, $totalCountIssues, 'totalCount should not match "total" pattern -- this is a count/quantity, not a monetary value');
    }
}
