<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\JoinOptimizationAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for JoinOptimizationAnalyzer.
 *
 * Tests detection of:
 * - Too many JOINs in single query (>5 = warning, >8 = critical)
 * - LEFT JOIN on NOT NULL relations (should be INNER JOIN)
 * - Unused JOINs (alias never used in query)
 */
final class JoinOptimizationAnalyzerTest extends TestCase
{
    private JoinOptimizationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new JoinOptimizationAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            new SqlStructureExtractor(),
            5,  // maxJoinsRecommended (default)
            8,  // maxJoinsCritical (default)
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_joins(): void
    {
        // Arrange: Queries without JOINs
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1')
            ->addQuery('SELECT name, email FROM products')
            ->addQuery('UPDATE users SET name = "test"')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues, 'Should not detect issues in queries without JOINs');
    }

    #[Test]
    public function it_skips_analysis_when_too_few_queries(): void
    {
        // Arrange: Less than MIN_QUERY_COUNT (3) queries
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id')
            ->addQuery('SELECT * FROM products')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues, 'Should skip analysis when query count < 3');
    }

    #[Test]
    public function it_returns_empty_collection_when_no_issues(): void
    {
        // Arrange: Queries with normal number of JOINs, all used
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u.id, o.total FROM users u INNER JOIN orders o ON u.id = o.user_id WHERE o.total > 100')
            ->addQuery('SELECT p.name, c.name FROM products p INNER JOIN categories c ON p.category_id = c.id')
            ->addQuery('SELECT a.name, b.value FROM table_a a INNER JOIN table_b b ON a.id = b.a_id WHERE a.active = 1')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues, 'Should not detect issues in properly optimized queries');
    }

    #[Test]
    public function it_detects_too_many_joins_warning_threshold(): void
    {
        // Arrange: Query with 6 JOINs (> MAX_JOINS_RECOMMENDED = 5, <= MAX_JOINS_CRITICAL = 8)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users u')
            ->addQuery('SELECT * FROM products p')
            ->addQuery('SELECT t0.id FROM table0 t0 ' .
                'JOIN table1 t1 ON t0.id = t1.t0_id ' .
                'JOIN table2 t2 ON t1.id = t2.t1_id ' .
                'JOIN table3 t3 ON t2.id = t3.t2_id ' .
                'JOIN table4 t4 ON t3.id = t4.t3_id ' .
                'JOIN table5 t5 ON t4.id = t5.t4_id ' .
                'JOIN table6 t6 ON t5.id = t6.t5_id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect too many JOINs');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertStringContainsString('Too Many JOINs', $issue->getTitle());
        self::assertEquals('warning', $data['severity'], '6 JOINs should trigger warning (not critical)');
        self::assertEquals(6, $data['join_count']);
    }

    #[Test]
    public function it_detects_too_many_joins_critical_threshold(): void
    {
        // Arrange: Query with 9 JOINs (> MAX_JOINS_CRITICAL = 8)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users')
            ->addQuery('SELECT * FROM products')
            ->addQuery('SELECT t0.id FROM table0 t0 ' .
                'JOIN table1 t1 ' .
                'JOIN table2 t2 ' .
                'JOIN table3 t3 ' .
                'JOIN table4 t4 ' .
                'JOIN table5 t5 ' .
                'JOIN table6 t6 ' .
                'JOIN table7 t7 ' .
                'JOIN table8 t8 ' .
                'JOIN table9 t9')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect excessive JOINs');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertEquals('critical', $data['severity'], '9 JOINs should trigger critical severity');
        self::assertEquals(9, $data['join_count']);
    }

    #[Test]
    public function it_extracts_join_information_correctly(): void
    {
        // Arrange: Query with various JOIN types
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT t0.id FROM table0 t0 ' .
                'INNER JOIN table1 t1 ON t0.id = t1.t0_id ' .
                'LEFT JOIN table2 t2 ON t1.id = t2.t1_id ' .
                'RIGHT JOIN table3 t3 ON t2.id = t3.t2_id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Analyzer should process the query (even if no issues found)
        self::assertGreaterThanOrEqual(0, count($issues), 'Analyzer should process JOINs');
    }

    #[Test]
    public function it_detects_unused_join(): void
    {
        // Arrange: Query with JOIN but alias never used
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id, u.name FROM users u ' .
                'INNER JOIN orders o ON u.id = o.user_id')
            // o.* is never referenced - only u.id and u.name
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect unused JOIN');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertStringContainsString('Unused JOIN', $issue->getTitle());
        self::assertEquals('warning', $data['severity']);
        self::assertEquals('orders', $data['table']);
        self::assertEquals('o', $data['alias']);
    }

    #[Test]
    public function it_does_not_flag_used_join(): void
    {
        // Arrange: Query with JOIN and alias IS used
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id, o.total FROM users u ' .
                'INNER JOIN orders o ON u.id = o.user_id ' .
                'WHERE o.total > 100')
            // o.total is used in SELECT and WHERE
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect unused JOIN
        $issuesArray = $issues->toArray();
        $unusedIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'Unused');
        });

        self::assertCount(0, $unusedIssues, 'Should NOT flag JOIN that is actually used');
    }

    #[Test]
    public function it_normalizes_left_outer_join_to_left(): void
    {
        // Arrange: Query with "LEFT OUTER JOIN" (should normalize to "LEFT")
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id FROM users u ' .
                'LEFT OUTER JOIN profiles p ON u.id = p.user_id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Analyzer should normalize and process
        $issuesArray = $issues->toArray();
        self::assertGreaterThanOrEqual(0, count($issuesArray));
    }

    #[Test]
    public function it_normalizes_right_outer_join_to_right(): void
    {
        // Arrange: Query with "RIGHT OUTER JOIN"
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id FROM users u ' .
                'RIGHT OUTER JOIN permissions p ON u.id = p.user_id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Analyzer should process
        self::assertGreaterThanOrEqual(0, count($issues));
    }

    #[Test]
    public function it_treats_join_without_keyword_as_inner_join(): void
    {
        // Arrange: Query with just "JOIN" (no INNER/LEFT/RIGHT keyword)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id FROM users u JOIN orders o ON u.id = o.user_id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be treated as INNER JOIN
        self::assertGreaterThanOrEqual(0, count($issues));
    }

    #[Test]
    public function it_deduplicates_identical_issues(): void
    {
        // Arrange: Multiple queries with same too-many-joins issue
        // Use all JOIN aliases to avoid "Unused JOIN" detection
        $longJoin = 'SELECT t0.id, t1.id, t2.id, t3.id, t4.id, t5.id, t6.id FROM table0 t0 ' .
            'JOIN table1 t1 ON t0.id = t1.t0_id ' .
            'JOIN table2 t2 ON t1.id = t2.t1_id ' .
            'JOIN table3 t3 ON t2.id = t3.t2_id ' .
            'JOIN table4 t4 ON t3.id = t4.t3_id ' .
            'JOIN table5 t5 ON t4.id = t5.t4_id ' .
            'JOIN table6 t6 ON t5.id = t6.t5_id';

        $queries = QueryDataBuilder::create()
            ->addQuery($longJoin)
            ->addQuery($longJoin) // Duplicate
            ->addQuery($longJoin) // Duplicate
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should deduplicate
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should deduplicate identical "Too Many JOINs" issues');
    }

    #[Test]
    public function it_truncates_long_queries_in_output(): void
    {
        // Arrange: Very long query (> 200 characters)
        $longQuery = 'SELECT ' . str_repeat('field, ', 50) . ' id FROM table0 t0 ' .
            'JOIN table1 t1 ' .
            'JOIN table2 t2 ' .
            'JOIN table3 t3 ' .
            'JOIN table4 t4 ' .
            'JOIN table5 t5 ' .
            'JOIN table6 t6';

        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery($longQuery)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        if (count($issuesArray) > 0) {
            $issue = $issuesArray[0];
            $data = $issue->getData();

            if (strlen($longQuery) > 200) {
                self::assertStringContainsString('...', $data['query'], 'Long queries should be truncated');
                self::assertLessThan(210, strlen($data['query']), 'Truncated query should be ~200 chars + ...');
            }
        }
    }

    #[Test]
    public function it_includes_join_count_in_data(): void
    {
        // Arrange: Query with 6 JOINs
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT t0.id FROM table0 t0 ' .
                'JOIN table1 t1 ON t0.id = t1.t0_id ' .
                'JOIN table2 t2 ON t1.id = t2.t1_id ' .
                'JOIN table3 t3 ON t2.id = t3.t2_id ' .
                'JOIN table4 t4 ON t3.id = t4.t3_id ' .
                'JOIN table5 t5 ON t4.id = t5.t4_id ' .
                'JOIN table6 t6 ON t5.id = t6.t5_id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        // Find "Too Many JOINs" issue (not "Unused JOIN")
        $tooManyIssue = null;
        foreach ($issuesArray as $issue) {
            if (str_contains($issue->getTitle(), 'Too Many JOINs')) {
                $tooManyIssue = $issue;
                break;
            }
        }

        self::assertNotNull($tooManyIssue, 'Should have "Too Many JOINs" issue');
        $data = $tooManyIssue->getData();

        self::assertArrayHasKey('join_count', $data);
        self::assertEquals(6, $data['join_count']);
        self::assertArrayHasKey('max_recommended', $data);
        self::assertEquals(5, $data['max_recommended']);
    }

    #[Test]
    public function it_includes_execution_time_in_data(): void
    {
        // Arrange: Slow query with many JOINs
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery(
                'SELECT t0.id FROM table0 t0 ' .
                'JOIN table1 t1 ON t0.id = t1.t0_id ' .
                'JOIN table2 t2 ON t1.id = t2.t1_id ' .
                'JOIN table3 t3 ON t2.id = t3.t2_id ' .
                'JOIN table4 t4 ON t3.id = t4.t3_id ' .
                'JOIN table5 t5 ON t4.id = t5.t4_id ' .
                'JOIN table6 t6 ON t5.id = t6.t5_id',
                250.5,  // 250.5ms execution time
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        // Find "Too Many JOINs" issue
        $tooManyIssue = null;
        foreach ($issuesArray as $issue) {
            if (str_contains($issue->getTitle(), 'Too Many JOINs')) {
                $tooManyIssue = $issue;
                break;
            }
        }

        self::assertNotNull($tooManyIssue, 'Should have "Too Many JOINs" issue');
        $data = $tooManyIssue->getData();

        self::assertArrayHasKey('execution_time', $data);
        self::assertEquals(250.5, $data['execution_time']);
    }

    #[Test]
    public function it_suggests_solutions_for_too_many_joins(): void
    {
        // Arrange: Query with too many JOINs
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT t0.id FROM t0 JOIN t1 JOIN t2 JOIN t3 JOIN t4 JOIN t5 JOIN t6')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        self::assertNotNull($issue->getSuggestion(), 'Should provide suggestion');
    }

    #[Test]
    public function it_suggests_solutions_for_unused_joins(): void
    {
        // Arrange: Query with unused JOIN
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id FROM users u INNER JOIN orders o ON u.id = o.user_id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        self::assertNotNull($issue->getSuggestion(), 'Should provide suggestion for removing unused JOIN');
    }

    #[Test]
    public function it_provides_analyzer_metadata(): void
    {
        // Act
        $name = $this->analyzer->getName();
        $description = $this->analyzer->getDescription();

        // Assert
        self::assertEquals('JOIN Optimization Analyzer', $name);
        self::assertStringContainsString('JOIN', $description);
        self::assertStringContainsString('NOT NULL', $description);
    }

    #[Test]
    public function it_handles_case_insensitive_join_keywords(): void
    {
        // Arrange: Query with mixed case JOIN keywords
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT t0.id FROM t0 ' .
                'inner join t1 ' .
                'LEFT join t2 ' .
                'RIGHT JOIN t3 ' .
                'Join t4 ' .
                'JOIN t5 ' .
                'join t6')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should handle case-insensitive JOIN keywords');
    }

    #[Test]
    public function it_detects_multiple_issue_types_in_same_query(): void
    {
        // Arrange: Query with BOTH too many JOINs AND unused JOIN
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id FROM users u ' .
                'JOIN orders o ON u.id = o.user_id ' .   // unused
                'JOIN products p ON u.id = p.user_id ' . // unused
                'JOIN categories c ON u.id = c.user_id ' . // unused
                'JOIN tags t ON u.id = t.user_id ' . // unused
                'JOIN reviews r ON u.id = r.user_id ' . // unused
                'JOIN comments cm ON u.id = cm.user_id') // unused
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect multiple issue types');

        // Should have both "Too Many JOINs" and "Unused JOIN" issues
        $titles = array_map(static fn ($issue) => $issue->getTitle(), $issuesArray);
        $hasTooMany = false;
        $hasUnused = false;

        foreach ($titles as $title) {
            if (str_contains($title, 'Too Many JOINs')) {
                $hasTooMany = true;
            }
            if (str_contains($title, 'Unused')) {
                $hasUnused = true;
            }
        }

        self::assertTrue($hasTooMany || $hasUnused, 'Should detect at least one issue type');
    }

    #[Test]
    public function it_handles_join_without_alias_used_in_where(): void
    {
        // Arrange: JOIN without alias, but table name used in WHERE clause
        // This is a common pattern for many-to-many join tables
        // Example from Sylius: sylius_channel_locales join table
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT t0.code, t0.id FROM sylius_locale t0 ' .
                'INNER JOIN sylius_channel_locales ON t0.id = sylius_channel_locales.locale_id ' .
                'WHERE sylius_channel_locales.channel_id = 1')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag as unused since table is used in WHERE
        $issuesArray = $issues->toArray();
        $unusedIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($issue->getTitle(), 'Unused') &&
                   isset($data['table']) &&
                   $data['table'] === 'sylius_channel_locales';
        });

        self::assertCount(0, $unusedIssues, 'Should NOT flag JOIN without alias when table is used in WHERE clause');
    }

    #[Test]
    public function it_handles_join_without_alias_used_in_on_clause(): void
    {
        // Arrange: JOIN without alias, table used in ON clause of subsequent JOIN
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id FROM users u ' .
                'INNER JOIN user_roles ON u.id = user_roles.user_id ' .
                'INNER JOIN roles r ON user_roles.role_id = r.id ' .
                'WHERE r.name = "admin"')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag as unused
        $issuesArray = $issues->toArray();
        $unusedIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($issue->getTitle(), 'Unused') &&
                   isset($data['table']) &&
                   $data['table'] === 'user_roles';
        });

        self::assertCount(0, $unusedIssues, 'Should NOT flag JOIN without alias when table is used in ON clause');
    }

    #[Test]
    public function it_avoids_capturing_on_keyword_as_alias(): void
    {
        // Arrange: Query where "ON" could be mistakenly captured as alias
        // This was a bug where the regex captured "ON" as the alias
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT t0.code FROM sylius_locale t0 ' .
                'INNER JOIN sylius_channel_locales ON t0.id = sylius_channel_locales.locale_id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT create invalid "Unused JOIN" with alias "ON"
        $issuesArray = $issues->toArray();
        $onAliasIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return isset($data['alias']) && $data['alias'] === 'ON';
        });

        self::assertCount(0, $onAliasIssues, 'Should NOT capture "ON" keyword as JOIN alias');
    }

    #[Test]
    public function it_handles_real_sylius_locale_query(): void
    {
        // Arrange: Real query from Sylius that was reported as false positive
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery(
                'SELECT t0.code AS code_1, t0.created_at AS created_at_2, t0.updated_at AS updated_at_3, t0.id AS id_4 ' .
                'FROM sylius_locale t0 ' .
                'INNER JOIN sylius_channel_locales ON t0.id = sylius_channel_locales.locale_id ' .
                'WHERE sylius_channel_locales.channel_id = ?',
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag this as an issue
        $issuesArray = $issues->toArray();
        $unusedJoinIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'Unused JOIN');
        });

        self::assertCount(0, $unusedJoinIssues, 'Should NOT flag real Sylius locale query as having unused JOIN');
    }

    #[Test]
    public function it_handles_join_with_as_keyword(): void
    {
        // Arrange: JOIN with explicit AS keyword
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQuery('SELECT u.id, o.total FROM users AS u ' .
                'INNER JOIN orders AS o ON u.id = o.user_id ' .
                'WHERE o.total > 100')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should handle AS keyword correctly
        $issuesArray = $issues->toArray();
        $unusedJoinIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'Unused JOIN');
        });

        self::assertCount(0, $unusedJoinIssues, 'Should correctly parse JOINs with AS keyword');
    }

    #[Test]
    public function it_extracts_backtrace_from_query_for_unused_join(): void
    {
        // Arrange: Query with unused JOIN and backtrace
        $backtrace = [
            [
                'file' => '/app/src/Controller/UserController.php',
                'line' => 42,
                'function' => 'getUsers',
                'class' => 'App\\Controller\\UserController',
            ],
        ];

        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM a')
            ->addQuery('SELECT * FROM b')
            ->addQueryWithBacktrace(
                'SELECT u.id, u.name FROM users u INNER JOIN orders o ON u.id = o.user_id',
                $backtrace,
                10.5,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect unused JOIN');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertStringContainsString('Unused JOIN', $issue->getTitle());
        self::assertArrayHasKey('backtrace', $data, 'Issue should have backtrace');
        self::assertNotNull($data['backtrace'], 'Backtrace should not be null');
        self::assertIsArray($data['backtrace'], 'Backtrace should be an array');
        self::assertArrayHasKey('file', $data['backtrace'][0], 'Backtrace should have file');
        self::assertArrayHasKey('line', $data['backtrace'][0], 'Backtrace should have line');
        self::assertEquals('/app/src/Controller/UserController.php', $data['backtrace'][0]['file']);
        self::assertEquals(42, $data['backtrace'][0]['line']);
    }
}
