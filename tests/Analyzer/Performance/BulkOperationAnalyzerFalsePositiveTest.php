<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\BulkOperationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BulkOperationAnalyzerFalsePositiveTest extends TestCase
{
    private BulkOperationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new BulkOperationAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            20,
        );
    }

    #[Test]
    public function it_falsely_flags_diverse_updates_on_same_table_as_bulk(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 25; ++$i) {
            $columns = ['name', 'email', 'status', 'role', 'phone'];
            $col = $columns[$i % count($columns)];
            $builder->addQuery(
                sprintf("UPDATE users SET %s = 'value_%d' WHERE id = %d", $col, $i, $i),
                0.001,
            );
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Should not flag diverse UPDATE queries updating different columns as a single bulk operation');
    }

    #[Test]
    public function it_correctly_flags_identical_bulk_updates(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 25; ++$i) {
            $builder->addQuery(
                sprintf("UPDATE users SET status = 'inactive' WHERE id = %d", $i),
                0.001,
            );
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThan(0, count($issues->toArray()), 'Should flag identical bulk UPDATE pattern');
    }
}
