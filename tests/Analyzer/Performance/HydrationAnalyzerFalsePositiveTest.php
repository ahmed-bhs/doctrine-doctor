<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\HydrationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HydrationAnalyzerFalsePositiveTest extends TestCase
{
    private HydrationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new HydrationAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_scalar_count_query_with_high_limit(): void
    {
        $sql = 'SELECT COUNT(*) FROM users LIMIT 500';

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Should not flag scalar COUNT(*) as excessive hydration');
    }

    #[Test]
    public function it_falsely_flags_scalar_aggregation_with_high_row_count(): void
    {
        $sql = 'SELECT SUM(amount) FROM orders WHERE status = ?';

        $collection = QueryDataBuilder::create()
            ->addQueryWithRowCount($sql, 500)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Should not flag SUM() aggregation returning 1 scalar row as excessive hydration');
    }

    #[Test]
    public function it_falsely_flags_select_id_only_with_high_limit(): void
    {
        $sql = 'SELECT id FROM users WHERE active = 1 LIMIT 200';

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Should not flag single-column scalar SELECT as excessive hydration');
    }

    #[Test]
    public function it_correctly_flags_full_entity_hydration_with_high_row_count(): void
    {
        $sql = 'SELECT u.id, u.name, u.email, u.address, u.phone FROM users u LIMIT 500';

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThan(0, count($issues->toArray()), 'Should flag full entity hydration with many rows');
    }
}
