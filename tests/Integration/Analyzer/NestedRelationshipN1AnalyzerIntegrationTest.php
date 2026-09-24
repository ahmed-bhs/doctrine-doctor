<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\NestedRelationshipN1Analyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\BlogFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\UserFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the analyzer on the SQL Doctrine actually generates for a nested
 * lazy-loading loop, where each level is loaded in turn rather than in blocks.
 */
final class NestedRelationshipN1AnalyzerIntegrationTest extends DatabaseTestCase
{
    private NestedRelationshipN1Analyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new NestedRelationshipN1Analyzer(
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
    public function it_detects_lazy_loading_two_levels_deep_in_a_loop(): void
    {
        $this->startQueryCollection();

        foreach ($this->entityManager->getRepository(Comment::class)->findAll() as $comment) {
            $comment->getPost()->getAuthor()->getName();
        }

        $issues = $this->analyzer->analyze($this->stopQueryCollection())->toArray();

        self::assertCount(1, $issues);
        self::assertSame('nested_n_plus_one', $issues[0]->getType());
    }

    #[Test]
    public function it_stays_silent_when_both_levels_are_fetch_joined(): void
    {
        $this->startQueryCollection();

        $comments = $this->entityManager
            ->createQuery('SELECT c, p, a FROM ' . Comment::class . ' c JOIN c.post p JOIN p.author a')
            ->getResult();

        foreach ($comments as $comment) {
            $comment->getPost()->getAuthor()->getName();
        }

        self::assertCount(0, $this->analyzer->analyze($this->stopQueryCollection()));
    }
}
