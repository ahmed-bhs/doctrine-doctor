<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\DQLPatternMatcher;
use AhmedBhs\DoctrineDoctor\Analyzer\Security\DQLInjectionAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityAnalyzerLeakTest extends TestCase
{
    private DQLInjectionAnalyzer $dqlAnalyzer;

    private DQLPatternMatcher $matcher;

    protected function setUp(): void
    {
        $this->dqlAnalyzer = new DQLInjectionAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $this->matcher = new DQLPatternMatcher();
    }

    #[Test]
    public function it_must_not_classify_plain_sql_with_column_alias_as_doctrine_dql(): void
    {
        $plainSql = 'SELECT name AS user_1, email AS user_2 FROM users';

        self::assertFalse(
            $this->matcher->hasDoctrineSQLPattern($plainSql),
            'plain hand-written SQL with column alias must not be classified as Doctrine-generated DQL',
        );
    }

    #[Test]
    public function it_must_not_flag_safe_parameterized_dql_with_column_aliases(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQueryWithParams(
                'SELECT name AS first_name, email AS user_email FROM users WHERE active = ?',
                [1],
            )
            ->build();

        $issues = $this->dqlAnalyzer->analyze($queries);

        self::assertCount(
            0,
            $issues,
            'safe parameterized SQL with column aliases must not raise DQL injection alert',
        );
    }
}
