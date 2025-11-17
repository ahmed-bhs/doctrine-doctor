<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
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
        $issueFactory = new IssueFactory();
        $suggestionFactory = new SuggestionFactory(new PhpTemplateRenderer());

        $this->analyzer = new UnusedEagerLoadAnalyzer($issueFactory, $suggestionFactory);
    }

    #[Test]
    public function it_detects_unused_join_in_select_query(): void
    {
        // Query with JOIN but alias 'u' never used in SELECT/WHERE/ORDER BY
        $sql = 'SELECT a.id, a.title FROM article a LEFT JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 10.0)->build();

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

        $collection = QueryDataBuilder::create()->addQuery($sql, 10.0)->build();

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

        $collection = QueryDataBuilder::create()->addQuery($sql, 10.0)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues); // No issues - alias is used
    }

    #[Test]
    public function it_detects_over_eager_loading_with_many_joins(): void
    {
        $sql = 'SELECT a.id FROM article a '
            . 'LEFT JOIN user u ON u.id = a.author_id '
            . 'LEFT JOIN category c ON c.id = a.category_id '
            . 'LEFT JOIN tag t ON t.id = a.tag_id '
            . 'LEFT JOIN comment cm ON cm.article_id = a.id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 10.0)->build();

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

        $collection = QueryDataBuilder::create()->addQuery($sql, 10.0)->build();

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

        $collection = QueryDataBuilder::create()->addQuery($sql, 10.0)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_non_select_queries(): void
    {
        $sql = 'UPDATE article a LEFT JOIN user u ON u.id = a.author_id SET a.title = ?';

        $collection = QueryDataBuilder::create()->addQuery($sql, 10.0)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues); // UPDATE queries not analyzed
    }

    #[Test]
    public function it_creates_suggestion_for_unused_eager_load(): void
    {
        $sql = 'SELECT a.id FROM article a LEFT JOIN user u ON u.id = a.author_id';

        $collection = QueryDataBuilder::create()->addQuery($sql, 10.0)->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertStringContainsString('Remove', $suggestion->getCode());
        self::assertStringContainsString('unused', strtolower($suggestion->getCode()));
    }
}
