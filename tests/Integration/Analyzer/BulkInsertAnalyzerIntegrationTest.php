<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\BulkInsertAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the analyzer on the SQL Doctrine generates when an import persists
 * entities one by one, and on the same rows written with multi-row INSERTs.
 */
final class BulkInsertAnalyzerIntegrationTest extends DatabaseTestCase
{
    private BulkInsertAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new BulkInsertAnalyzer(PlatformAnalyzerTestHelper::createSuggestionFactory(), $this->entityManager);
        $this->createSchema([User::class]);
    }

    #[Test]
    public function it_reports_an_import_persisting_entities_one_by_one(): void
    {
        $this->startQueryCollection();

        for ($i = 1; $i <= 150; ++$i) {
            $user = new User();
            $user->setName('User ' . $i);
            $user->setEmail('user' . $i . '@example.com');
            $this->entityManager->persist($user);
        }

        $this->entityManager->flush();

        $issues = $this->analyzer->analyze($this->stopQueryCollection())->toArray();

        self::assertCount(1, $issues);
        self::assertStringContainsString('150', $issues[0]->getTitle());
    }

    #[Test]
    public function it_stays_silent_for_the_same_rows_inserted_with_dbal_in_batches(): void
    {
        $this->startQueryCollection();

        $connection = $this->entityManager->getConnection();
        foreach (array_chunk(range(1, 150), 50) as $chunk) {
            $connection->executeStatement(
                'INSERT INTO users (name, email) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)')),
                array_merge(...array_map(static fn (int $i): array => ['User ' . $i, 'user' . $i . '@example.com'], $chunk)),
            );
        }

        self::assertCount(0, $this->analyzer->analyze($this->stopQueryCollection()));
        self::assertSame(150, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
    }
}
