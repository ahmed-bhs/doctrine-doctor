<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\IneffectiveLikeAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IneffectiveLikeAnalyzerFalsePositiveTest extends TestCase
{
    private IneffectiveLikeAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new IneffectiveLikeAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            0.0,
        );
    }

    #[Test]
    public function it_falsely_flags_param_starting_with_percent_when_query_has_no_like_clause(): void
    {
        $sql = "SELECT * FROM products WHERE format = ? AND category LIKE 'Electronics%'";

        $collection = QueryDataBuilder::create()
            ->addQueryWithParams($sql, ['%pdf%'], 10.0)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $paramIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getDescription(), '%pdf%'),
        );

        self::assertCount(0, $paramIssues, 'Should not flag a param starting with % when it is not bound to a LIKE clause');
    }

    #[Test]
    public function it_falsely_flags_param_with_percent_on_unrelated_query(): void
    {
        $sql = 'SELECT id, name FROM reports WHERE type LIKE ? AND status = ?';

        $collection = QueryDataBuilder::create()
            ->addQueryWithParams($sql, ['summary%', '%archived%'], 10.0)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $archivedIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getDescription(), '%archived%'),
        );

        self::assertCount(0, $archivedIssues, 'Should not flag params with % that are not bound to the LIKE clause');
    }
}
