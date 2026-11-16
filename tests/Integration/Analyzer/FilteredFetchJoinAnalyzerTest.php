<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FilteredFetchJoinAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;

final class FilteredFetchJoinAnalyzerTest extends DatabaseTestCase
{
    private FilteredFetchJoinAnalyzer $analyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, BlogPost::class, Comment::class]);

        $sqlExtractor = new SqlStructureExtractor();
        $this->analyzer = new FilteredFetchJoinAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            new IssueFactory(),
            $sqlExtractor,
            new CollectionJoinDetector($this->entityManager, $sqlExtractor),
        );
    }

    #[Test]
    public function it_flags_a_collection_that_is_fetch_joined_and_filtered_in_where(): void
    {
        $issues = $this->analyze(
            'SELECT b0_.id, b0_.title, c1_.id AS id_2, c1_.content FROM blog_posts b0_ '
            . 'INNER JOIN comments c1_ ON b0_.id = c1_.post_id WHERE c1_.author_id = ? ORDER BY b0_.id',
        );

        self::assertCount(1, $issues);
        self::assertSame('Filtered Fetch Join: comments', $issues[0]->getTitle());
        self::assertStringContainsString('c1_.author_id = ?', $issues[0]->getDescription());
        self::assertSame('critical', $issues[0]->getSeverity()->value);
    }

    #[Test]
    public function it_ignores_a_filter_on_the_root_entity_or_a_to_one_join(): void
    {
        self::assertCount(0, $this->analyze(
            'SELECT b0_.id, c1_.id AS id_2 FROM blog_posts b0_ INNER JOIN comments c1_ ON b0_.id = c1_.post_id WHERE b0_.id = ?',
        ));
        self::assertCount(0, $this->analyze(
            'SELECT b0_.id, u1_.id AS id_2, u1_.name FROM blog_posts b0_ INNER JOIN users u1_ ON b0_.author_id = u1_.id WHERE u1_.name = ?',
        ));
    }

    #[Test]
    public function it_ignores_a_collection_used_only_to_filter(): void
    {
        // The fix: one alias filters (not selected), another loads the whole collection.
        self::assertCount(0, $this->analyze(
            'SELECT b0_.id, c2_.id AS id_2 FROM blog_posts b0_ '
            . 'INNER JOIN comments c1_ ON b0_.id = c1_.post_id AND (c1_.author_id = ?) '
            . 'LEFT JOIN comments c2_ ON b0_.id = c2_.post_id',
        ));
    }

    #[Test]
    public function it_ignores_a_grouped_aggregate_and_a_translation_filtered_on_its_locale(): void
    {
        self::assertCount(0, $this->analyze(
            'SELECT b0_.id, SUM(c1_.id) AS sclr_1 FROM blog_posts b0_ INNER JOIN comments c1_ ON b0_.id = c1_.post_id WHERE c1_.author_id = ? GROUP BY b0_.id',
        ));
        self::assertCount(0, $this->analyze(
            'SELECT b0_.id, c1_.id AS id_2 FROM blog_posts b0_ LEFT JOIN comments c1_ ON b0_.id = c1_.post_id WHERE c1_.locale = ?',
        ));
        self::assertCount(0, $this->analyze(
            'SELECT b0_.id, c1_.id AS id_2 FROM blog_posts b0_ INNER JOIN comments c1_ ON b0_.id = c1_.post_id AND (c1_.locale = ?) WHERE c1_.content = ?',
        ));
    }

    /**
     * @return list<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyze(string $sql): array
    {
        return array_values($this->analyzer->analyze(QueryDataBuilder::create()->addQuery($sql)->build())->toArray());
    }
}
