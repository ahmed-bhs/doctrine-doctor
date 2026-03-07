<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\SetMaxResultsWithCollectionJoinAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SetMaxResultsWithCollectionJoinAnalyzerFalsePositiveTest extends TestCase
{
    private SetMaxResultsWithCollectionJoinAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SetMaxResultsWithCollectionJoinAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            new SqlStructureExtractor(),
        );
    }

    #[Test]
    public function it_falsely_flags_limit_with_join_on_indexed_by_field(): void
    {
        $sql = 'SELECT p0_.id, p0_.name, s1_.id AS s1_id, s1_.setting_value '
            . 'FROM products p0_ '
            . 'LEFT JOIN product_settings s1_ ON s1_.product_id = p0_.id AND s1_.setting_key = ? '
            . 'LIMIT 10';

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Should not flag LIMIT with JOIN constrained by equality on indexed key (guarantees single row)');
    }

    #[Test]
    public function it_falsely_flags_limit_with_join_having_between_constraint(): void
    {
        $sql = 'SELECT o0_.id, o0_.total, p1_.id AS p1_id, p1_.price '
            . 'FROM orders o0_ '
            . 'LEFT JOIN order_prices p1_ ON p1_.order_id = o0_.id AND p1_.valid_from <= ? AND p1_.valid_to >= ? '
            . 'LIMIT 5';

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Should not flag LIMIT with JOIN constrained by date range (natural result limitation)');
    }
}
