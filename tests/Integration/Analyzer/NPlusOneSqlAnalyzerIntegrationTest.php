<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneSqlAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\BlogFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\UserFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * The analyzer covers N+1 loops in hand-written DBAL code. Lazy loading through
 * the ORM is NPlusOneAnalyzer's case, fixed with a fetch join: reporting it here
 * too duplicated the issue and blamed "DBAL code" for SQL Doctrine generated.
 */
final class NPlusOneSqlAnalyzerIntegrationTest extends DatabaseTestCase
{
    private NPlusOneSqlAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new NPlusOneSqlAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $this->createSchema([User::class, BlogPost::class, Comment::class]);

        $userFixture = new UserFixture();
        $userFixture->load($this->entityManager);
        new BlogFixture($userFixture->getUsers())->load($this->entityManager);

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    #[Test]
    public function it_leaves_orm_lazy_loading_to_the_orm_analyzer(): void
    {
        $this->startQueryCollection();

        foreach ($this->entityManager->getRepository(BlogPost::class)->findAll() as $post) {
            $post->getAuthor()->getName();
        }

        self::assertCount(0, $this->analyzer->analyze($this->stopQueryCollection()));
    }

    #[Test]
    public function it_reports_a_loop_of_dbal_queries_with_an_array_parameter_fix(): void
    {
        $connection = $this->entityManager->getConnection();
        $postIds    = $connection->fetchFirstColumn('SELECT id FROM blog_posts');

        $this->startQueryCollection();

        foreach ($postIds as $postId) {
            $connection->fetchAllAssociative('SELECT id, content FROM comments WHERE post_id = ?', [$postId]);
        }

        $issues = $this->analyzer->analyze($this->stopQueryCollection())->toArray();

        self::assertCount(1, $issues);
        self::assertStringContainsString('ArrayParameterType', (string) $issues[0]->getSuggestion()?->getCode());
    }
}
