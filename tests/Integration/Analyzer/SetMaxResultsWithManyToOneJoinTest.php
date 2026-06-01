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
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\SetMaxResultsWithCollectionJoinAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;

final class SetMaxResultsWithManyToOneJoinTest extends DatabaseTestCase
{
    private SetMaxResultsWithCollectionJoinAnalyzer $analyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, BlogPost::class, Comment::class]);

        $sqlExtractor = new SqlStructureExtractor();

        $this->analyzer = new SetMaxResultsWithCollectionJoinAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $sqlExtractor,
            new CollectionJoinDetector($this->entityManager, $sqlExtractor),
            $this->entityManager,
        );
    }

    #[Test]
    public function it_does_not_flag_limit_with_many_to_one_fetch_join(): void
    {
        $sql = 'SELECT b0_.id, b0_.title, u1_.id AS u1_id, u1_.name '
            . 'FROM blog_posts b0_ '
            . 'INNER JOIN users u1_ ON b0_.author_id = u1_.id '
            . 'LIMIT 20';

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues->toArray(), 'A ManyToOne fetch-join yields one row per root entity, so LIMIT is safe and must not be flagged');
    }

    #[Test]
    public function it_still_flags_limit_with_one_to_many_collection_join(): void
    {
        $sql = 'SELECT b0_.id, b0_.title, c1_.id AS c1_id, c1_.content '
            . 'FROM blog_posts b0_ '
            . 'INNER JOIN comments c1_ ON c1_.post_id = b0_.id '
            . 'LIMIT 20';

        $collection = QueryDataBuilder::create()->addQuery($sql)->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(1, $issues->toArray(), 'A OneToMany collection join multiplies rows, so LIMIT corrupts hydration and must be flagged');
    }
}
