<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\QueryBuilderBestPracticesAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryBuilderBestPracticesAnalyzerFalsePositiveTest extends TestCase
{
    private QueryBuilderBestPracticesAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new QueryBuilderBestPracticesAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_hardcoded_enum_value_as_sql_injection(): void
    {
        $sql = "SELECT u.id FROM users u WHERE u.status = 'active' AND u.role = 'admin'";

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        $injectionIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'SQL Injection'),
        );

        self::assertCount(0, $injectionIssues, 'Should not flag hardcoded enum/constant string values as SQL injection');
    }

    #[Test]
    public function it_falsely_flags_hardcoded_boolean_string_as_sql_injection(): void
    {
        $sql = "SELECT * FROM config WHERE enabled = 'true' AND module = 'core'";

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        $injectionIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'SQL Injection'),
        );

        self::assertCount(0, $injectionIssues, 'Should not flag hardcoded boolean/config strings as SQL injection');
    }
}
