<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\NullComparisonAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NullComparisonAnalyzerFalsePositiveTest extends TestCase
{
    private NullComparisonAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new NullComparisonAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_null_comparison_inside_string_literal(): void
    {
        $sql = "INSERT INTO audit_log (message) VALUES ('field = NULL means missing data')";

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Should not flag = NULL inside a string literal');
    }

    #[Test]
    public function it_falsely_flags_null_comparison_in_case_expression_string(): void
    {
        $sql = "SELECT CASE WHEN status = 'active' THEN 'ok' ELSE 'status = NULL' END FROM users";

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'Should not flag = NULL inside a CASE string value');
    }

    #[Test]
    public function it_correctly_flags_real_null_comparison_in_where(): void
    {
        $sql = 'SELECT * FROM users WHERE deleted_at = NULL';

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThan(0, count($issues->toArray()), 'Should flag real = NULL comparison in WHERE');
    }
}
