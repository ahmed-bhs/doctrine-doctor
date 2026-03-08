<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\DQLInjectionAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DQLInjectionAnalyzerFalsePositiveTest extends TestCase
{
    private DQLInjectionAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DQLInjectionAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_irish_name_with_escaped_apostrophe(): void
    {
        $sql = "SELECT u FROM users u WHERE u.last_name = 'O''Brien'";

        $collection = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues, "O''Brien should not trigger injection detection -- this is a legitimate SQL-escaped Irish name");
    }

    #[Test]
    public function it_falsely_flags_comment_guide_title_as_injection(): void
    {
        $sql = "SELECT a FROM articles a WHERE a.title = 'C# -- A Complete Guide'";

        $collection = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues, '-- inside a legitimate title string should not trigger comment syntax detection');
    }

    #[Test]
    public function it_falsely_escalates_risk_by_stacking_multiple_weak_indicators(): void
    {
        $sql = "SELECT p FROM products p WHERE p.name = 'United Kingdom' AND p.category = 'Electronics & Home'";

        $collection = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();
        $issues = $this->analyzer->analyze($collection);

        $criticalIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Vulnerability'),
        );

        self::assertCount(0, $criticalIssues, 'Legitimate literal strings should not stack risk_level to critical (>=3)');
    }
}
