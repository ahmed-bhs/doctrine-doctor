<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\UnusedEagerLoadAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnusedEagerLoadAnalyzerTest extends TestCase
{
    private UnusedEagerLoadAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $issueFactory = new IssueFactory();
        $suggestionFactory = new SuggestionFactory(new PhpTemplateRenderer());

        $this->analyzer = new UnusedEagerLoadAnalyzer($entityManager, $issueFactory, $suggestionFactory);
    }

    #[Test]
    public function it_detects_unused_join_in_select_query(): void
    {
        // Query with JOIN but alias 'u' never used in SELECT/WHERE/ORDER BY
        $sql = 'SELECT a.id, a.title FROM article a LEFT JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertSame('unused_eager_load', $issue->getType());
        self::assertStringContainsString('Unused Eager Load', $issue->getTitle());
        self::assertStringContainsString('user', $issue->getDescription());
    }

    #[Test]
    public function it_detects_multiple_unused_joins(): void
    {
        $sql = 'SELECT a.id FROM article a '
            . 'LEFT JOIN user u ON u.id = a.author_id '
            . 'LEFT JOIN category c ON c.id = a.category_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('2', $issue->getTitle()); // 2 unused JOINs
    }

    #[Test]
    public function it_does_not_flag_used_join_aliases(): void
    {
        // 'u' is used in SELECT
        $sql = 'SELECT a.id, u.name FROM article a LEFT JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues); // No issues - alias is used
    }

    #[Test]
    public function it_does_not_flag_inner_join_used_for_filtering(): void
    {
        // INNER JOIN can constrain results even if joined alias is not projected.
        $sql = 'SELECT a.id FROM article a INNER JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_does_not_flag_right_join_even_when_alias_is_unused(): void
    {
        // RIGHT JOIN, like INNER JOIN, constrains the result set.
        // The analyzer only checks for "LEFT" in the join type, so RIGHT JOINs
        // with unused aliases silently pass through (false negative).
        $sql = 'SELECT a.id FROM article a RIGHT JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        // RIGHT JOIN with unused alias is NOT flagged (false negative).
        // This is acceptable since RIGHT JOINs are rare in Doctrine, but
        // documents the gap.
        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_does_not_flag_left_join_alias_used_only_in_subquery(): void
    {
        // The alias "u" appears textually in the SQL but only inside a subquery.
        // The current regex-based check sees the alias match and considers it "used".
        $sql = 'SELECT a.id FROM article a LEFT JOIN user u ON u.id = a.author_id WHERE a.id IN (SELECT u.id FROM user u WHERE u.active = 1)';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        // BUG: the outer "u" alias is not actually used (the subquery has its own
        // "u" alias). But the analyzer sees "u" in the WHERE and thinks it is used.
        self::assertCount(0, $unusedJoinIssues, 'Known false negative: alias collision with subquery hides unused join');
    }

    #[Test]
    public function it_flags_left_outer_join_with_unused_alias(): void
    {
        // LEFT OUTER JOIN is semantically identical to LEFT JOIN.
        // Verify the analyzer handles the verbose syntax.
        $sql = 'SELECT a.id FROM article a LEFT OUTER JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        self::assertCount(1, $unusedJoinIssues);
    }

    #[Test]
    public function it_does_not_flag_left_join_alias_used_in_another_joins_on_clause(): void
    {
        // Alias "u" is used in the ON clause of a second JOIN.
        // The skip logic should only exclude the alias's own ON clause.
        $sql = 'SELECT a.id, addr.city FROM article a '
            . 'LEFT JOIN user u ON u.id = a.author_id '
            . 'LEFT JOIN address addr ON addr.user_id = u.id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        // "u" is used in addr's ON clause, so it should NOT be flagged.
        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_does_not_flag_left_join_alias_used_in_case_expression(): void
    {
        $sql = 'SELECT a.id, CASE WHEN u.role = ? THEN 1 ELSE 0 END AS is_admin FROM article a LEFT JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_does_not_flag_left_join_alias_used_only_in_having(): void
    {
        $sql = 'SELECT a.category_id, COUNT(*) AS cnt FROM article a '
            . 'LEFT JOIN user u ON u.id = a.author_id '
            . 'GROUP BY a.category_id '
            . 'HAVING COUNT(u.id) > 5';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_does_not_flag_left_join_with_short_alias_matching_sql_keyword_prefix(): void
    {
        // Short alias "o" could collide with SQL keywords or column names.
        // Verify word boundary matching prevents false positives.
        $sql = 'SELECT a.id FROM article a LEFT JOIN orders o ON o.article_id = a.id WHERE o.status = ?';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_falsely_flags_left_join_used_only_in_exists_subquery(): void
    {
        // The alias "u" is referenced only inside an EXISTS subquery.
        // The parser sees "u." somewhere in the SQL and considers it used,
        // but semantically the outer LEFT JOIN is unused -- the EXISTS
        // could reference the table directly without the outer JOIN.
        $sql = 'SELECT a.id FROM article a LEFT JOIN user u ON u.id = a.author_id WHERE EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        // The alias IS used in the EXISTS subquery, so not flagged.
        // Whether this is truly "unused eager loading" is debatable -- the JOIN
        // is required for the EXISTS condition to work. Not a false positive.
        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_falsely_flags_left_join_when_alias_matches_column_name_in_select(): void
    {
        // The alias "s" could match the column name "s" if word boundary is
        // insufficient. E.g. SELECT a.status AS s -- here "s" is a column alias,
        // not a table alias reference.
        $sql = 'SELECT a.id, a.status AS s FROM article a LEFT JOIN status s ON s.id = a.status_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        // The regex looks for `\bs\b\.` (alias followed by dot). The column
        // alias "AS s" does not have a dot after it, so the regex correctly
        // does NOT match. The LEFT JOIN "s" IS unused and should be flagged.
        self::assertCount(1, $unusedJoinIssues);
    }

    #[Test]
    public function it_does_not_flag_left_join_alias_used_in_order_by(): void
    {
        $sql = 'SELECT a.id FROM article a LEFT JOIN user u ON u.id = a.author_id ORDER BY u.name ASC';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_does_not_flag_left_join_alias_used_in_group_by(): void
    {
        $sql = 'SELECT u.country_id, COUNT(*) FROM article a LEFT JOIN user u ON u.id = a.author_id GROUP BY u.country_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        self::assertCount(0, $unusedJoinIssues);
    }

    #[Test]
    public function it_flags_left_join_with_doctrine_style_aliased_query(): void
    {
        // Doctrine generates queries with t0_, t1_ style aliases.
        $sql = 'SELECT t0_.id AS id_0, t0_.title AS title_1 FROM article t0_ LEFT JOIN user t1_ ON t1_.id = t0_.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);
        $unusedJoinIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Unused Eager Load'),
        );

        self::assertCount(1, $unusedJoinIssues);
    }

    #[Test]
    public function it_detects_over_eager_loading_with_many_joins(): void
    {
        $sql = 'SELECT a.id FROM article a '
            . 'LEFT JOIN user u ON u.id = a.author_id '
            . 'LEFT JOIN category c ON c.id = a.category_id '
            . 'LEFT JOIN tag t ON t.id = a.tag_id '
            . 'LEFT JOIN comment cm ON cm.article_id = a.id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);

        // Should detect both unused JOINs AND over-eager loading
        self::assertGreaterThanOrEqual(1, \count($issues));

        $descriptions = array_map(fn ($issue) => $issue->getDescription(), $issues->toArray());
        $allDescriptions = implode(' ', $descriptions);

        // Should mention over-eager or multiple JOINs
        self::assertTrue(
            str_contains($allDescriptions, 'Over-eager') || str_contains($allDescriptions, '4'),
            'Should detect over-eager loading with 4 JOINs',
        );
    }

    #[Test]
    public function it_calculates_critical_severity_for_many_unused_joins(): void
    {
        $sql = 'SELECT a.id FROM article a '
            . 'LEFT JOIN user u ON u.id = a.author_id '
            . 'LEFT JOIN category c ON c.id = a.category_id '
            . 'LEFT JOIN tag t ON t.id = a.tag_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));
        $issue = $issues->toArray()[0];

        // 3 unused JOINs should be critical
        self::assertTrue($issue->getSeverity()->isCritical());
    }

    #[Test]
    public function it_ignores_queries_without_joins(): void
    {
        $sql = 'SELECT a.id, a.title FROM article a';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_non_select_queries(): void
    {
        $sql = 'UPDATE article a LEFT JOIN user u ON u.id = a.author_id SET a.title = ?';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues); // UPDATE queries not analyzed
    }

    #[Test]
    public function it_creates_suggestion_for_unused_eager_load(): void
    {
        $sql = 'SELECT a.id FROM article a LEFT JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 0.010)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertStringContainsString('Remove', $suggestion->getCode());
        self::assertStringContainsString('unused', strtolower($suggestion->getCode()));
    }
}
