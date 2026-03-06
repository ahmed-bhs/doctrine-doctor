<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\DivisionByZeroAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DivisionByZeroAnalyzerFalsePositiveTest extends TestCase
{
    private DivisionByZeroAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DivisionByZeroAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_skips_unprotected_division_when_case_when_exists_elsewhere(): void
    {
        $sql = 'SELECT CASE WHEN status = 1 THEN \'active\' ELSE \'inactive\' END AS label, revenue / quantity AS unit_price FROM sales';

        $collection = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues), 'Unprotected division revenue / quantity should be detected even when CASE WHEN exists elsewhere in the query');
    }

    #[Test]
    public function it_falsely_skips_unprotected_division_when_nullif_protects_another(): void
    {
        $sql = 'SELECT cost / NULLIF(quantity, 0) AS unit_cost, revenue / margin AS ratio FROM sales';

        $collection = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues), 'Unprotected division revenue / margin should be detected even when NULLIF protects a different divisor');
    }
}
