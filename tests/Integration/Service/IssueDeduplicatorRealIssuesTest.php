<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Service;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\GetReferenceAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\LazyLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\QueryCachingOpportunityAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\BlogFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\UserFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * One N+1 loop triggers several analyzers (N+1, lazy loading, repeated query).
 * The deduplicator merges their issues when they name the same table and
 * count; these tests feed it the issues the real analyzers produce for the
 * SQL Doctrine generates, so a change in titles or descriptions cannot break
 * the merge unnoticed.
 */
final class IssueDeduplicatorRealIssuesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema([User::class, BlogPost::class, Comment::class]);

        $userFixture = new UserFixture();
        $userFixture->load($this->entityManager);
        new BlogFixture($userFixture->getUsers())->load($this->entityManager);

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    #[Test]
    public function it_reports_a_lazy_to_one_loop_once(): void
    {
        $this->startQueryCollection();

        foreach ($this->entityManager->getRepository(BlogPost::class)->findAll() as $post) {
            $post->getAuthor()->getName();
        }

        self::assertSame(['N+1 Query Detected: 10 queries (proxy)'], $this->repeatedQueryTitles($this->stopQueryCollection()));
    }

    #[Test]
    public function it_reports_a_lazy_collection_loop_once(): void
    {
        $this->startQueryCollection();

        foreach ($this->entityManager->getRepository(BlogPost::class)->findAll() as $post) {
            \count($post->getComments());
        }

        self::assertSame(['N+1 Query Detected: 10 queries (collection)'], $this->repeatedQueryTitles($this->stopQueryCollection()));
    }

    /**
     * Titles left after deduplication, without the unrelated findAll() notice.
     * @return list<string>
     */
    private function repeatedQueryTitles(QueryDataCollection $queries): array
    {
        $issueFactory      = PlatformAnalyzerTestHelper::createIssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $analyzers         = [
            new NPlusOneAnalyzer($this->entityManager, $issueFactory, $suggestionFactory),
            new LazyLoadingAnalyzer($issueFactory, $suggestionFactory),
            new GetReferenceAnalyzer($issueFactory, $suggestionFactory),
            new QueryCachingOpportunityAnalyzer($suggestionFactory),
        ];

        $issues = [];
        foreach ($analyzers as $analyzer) {
            foreach ($analyzer->analyze($queries) as $issue) {
                $issues[] = $issue;
            }
        }

        return array_values(array_map(
            static fn (IssueInterface $issue): string => $issue->getTitle(),
            new IssueDeduplicator()->deduplicate(IssueCollection::fromArray($issues))->toArray(),
        ));
    }
}
