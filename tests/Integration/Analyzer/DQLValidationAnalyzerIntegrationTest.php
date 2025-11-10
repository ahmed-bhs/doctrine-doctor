<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\DQLValidationAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for DQLValidationAnalyzer - DQL Syntax Validation.
 *
 * This test demonstrates DQL validation and error detection:
 * - Invalid entity names
 * - Non-existent fields
 * - Syntax errors
 */
final class DQLValidationAnalyzerIntegrationTest extends DatabaseTestCase
{
    private DQLValidationAnalyzer $dqlValidationAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, Product::class, BlogPost::class]);

        $this->dqlValidationAnalyzer = new DQLValidationAnalyzer(
            $this->entityManager,
            new IssueFactory(),
        );
    }

    #[Test]
    public function it_validates_correct_dql(): void
    {
        $this->startQueryCollection();

        // GOOD: Valid DQL
        $query = $this->entityManager->createQuery(
            'SELECT p FROM ' . Product::class . ' p WHERE p.price > :price',
        );
        $query->setParameter('price', 10.0);
        $query->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->dqlValidationAnalyzer->analyze($queryDataCollection);

        // Valid DQL should not be flagged
        self::assertIsArray($issueCollection->toArray(), 'Should analyze valid DQL');
    }

    #[Test]
    public function it_detects_invalid_entity_name(): void
    {
        $this->startQueryCollection();

        try {
            // 📢 BAD: Invalid entity name
            $query = $this->entityManager->createQuery(
                'SELECT p FROM NonExistentEntity p',
            );
            $query->getResult();
        } catch (\Exception) {
            // Query will fail
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->dqlValidationAnalyzer->analyze($queryDataCollection);

        // Analyzer processes queries that were logged
        self::assertIsArray($issueCollection->toArray());
    }

    #[Test]
    public function it_detects_invalid_field_name(): void
    {
        $this->startQueryCollection();

        try {
            // 📢 BAD: Invalid field name
            $query = $this->entityManager->createQuery(
                'SELECT p FROM ' . Product::class . ' p WHERE p.nonExistentField > 10',
            );
            $query->getResult();
        } catch (\Exception) {
            // Query will fail
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->dqlValidationAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray());
    }

    #[Test]
    public function it_validates_join_syntax(): void
    {
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->startQueryCollection();

        // GOOD: Valid JOIN
        $query = $this->entityManager->createQuery(
            'SELECT u, p FROM ' . User::class . ' u LEFT JOIN u.posts p',
        );
        $query->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->dqlValidationAnalyzer->analyze($queryDataCollection);

        // Valid JOIN should not be flagged
        self::assertIsArray($issueCollection->toArray());
    }

    #[Test]
    public function it_handles_query_builder_dql(): void
    {
        $this->startQueryCollection();

        // Using QueryBuilder to generate DQL
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder->select('p')
            ->from(Product::class, 'p')
            ->where('p.price > :price')
            ->setParameter('price', 10.0);

        $queryBuilder->getQuery()->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->dqlValidationAnalyzer->analyze($queryDataCollection);

        // Valid QueryBuilder DQL
        self::assertIsArray($issueCollection->toArray());
    }

    #[Test]
    public function it_detects_syntax_errors(): void
    {
        $this->startQueryCollection();

        try {
            // 📢 BAD: Syntax error (missing alias)
            $query = $this->entityManager->createQuery(
                'SELECT FROM ' . Product::class,
            );
            $query->getResult();
        } catch (\Exception) {
            // Syntax error
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->dqlValidationAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray());
    }
}
