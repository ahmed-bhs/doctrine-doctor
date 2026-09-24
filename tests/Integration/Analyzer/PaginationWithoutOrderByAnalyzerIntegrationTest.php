<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\PaginationWithoutOrderByAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\BlogFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\UserFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use Doctrine\ORM\Tools\Pagination\Paginator;
use PHPUnit\Framework\Attributes\Test;

/**
 * Doctrine's Paginator moves the DQL ORDER BY into a derived table and puts
 * the LIMIT on the outer query, which has no ORDER BY of its own. The order is
 * defined, and the user cannot move it.
 */
final class PaginationWithoutOrderByAnalyzerIntegrationTest extends DatabaseTestCase
{
    private PaginationWithoutOrderByAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new PaginationWithoutOrderByAnalyzer(PlatformAnalyzerTestHelper::createSuggestionFactory());
        $this->createSchema([User::class, BlogPost::class, Comment::class]);

        $userFixture = new UserFixture();
        $userFixture->load($this->entityManager);
        new BlogFixture($userFixture->getUsers())->load($this->entityManager);

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    #[Test]
    public function it_accepts_the_order_by_the_paginator_moves_into_a_subquery(): void
    {
        self::assertCount(0, $this->paginate('SELECT p, c FROM ' . BlogPost::class . ' p LEFT JOIN p.comments c ORDER BY p.id'));
    }

    #[Test]
    public function it_reports_a_paginator_query_without_any_order_by(): void
    {
        self::assertCount(1, $this->paginate('SELECT p, c FROM ' . BlogPost::class . ' p LEFT JOIN p.comments c'));
    }

    private function paginate(string $dql): \AhmedBhs\DoctrineDoctor\Collection\IssueCollection
    {
        $this->startQueryCollection();

        iterator_to_array(new Paginator($this->entityManager->createQuery($dql)->setMaxResults(5)));

        return $this->analyzer->analyze($this->stopQueryCollection());
    }
}
