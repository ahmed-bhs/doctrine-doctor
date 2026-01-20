<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Test repository with intentional SQL injection vulnerabilities.
 * Used to test SQLInjectionInRawQueriesAnalyzer.
 */
class VulnerableRepository extends EntityRepository
{
    /**
     * VULNERABLE: String concatenation.
     */
    public function findByNameUnsafe(string $name): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT * FROM users WHERE name = '" . $name . "'"; // SQL injection!

        return $connection->executeQuery($sql)->fetchAllAssociative();
    }

    /**
     * VULNERABLE: Variable interpolation.
     */
    public function findByIdUnsafe(int $id): ?array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT * FROM users WHERE id = {$id}"; // Still vulnerable!

        return $connection->executeQuery($sql)->fetchAssociative() ?: null;
    }

    /**
     * VULNERABLE: Missing parameters with concatenation.
     */
    public function searchUnsafe(string $search): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT * FROM users WHERE name LIKE '%";
        $sql .= $search; // Concatenation
        $sql .= "%'";

        return $connection->executeQuery($sql)->fetchAllAssociative();
    }

    /**
     * VULNERABLE: sprintf with user input from request.
     */
    public function findByEmailUnsafe(): ?array
    {
        $connection = $this->getEntityManager()->getConnection();
        $email = $_GET['email'] ?? ''; // User input from GET parameter
        $sql = sprintf("SELECT * FROM users WHERE email = '%s'", $email); // SQL injection via sprintf!

        return $connection->executeQuery($sql)->fetchAssociative() ?: null;
    }

    /**
     * SECURE: Parameterized query (should NOT be flagged).
     */
    public function findByNameSafe(string $name): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = 'SELECT * FROM users WHERE name = :name';

        return $connection->executeQuery($sql, ['name' => $name])->fetchAllAssociative();
    }

    /**
     * SECURE: Query builder (should NOT be flagged).
     */
    public function findByIdSafe(int $id): ?array
    {
        $connection = $this->getEntityManager()->getConnection();
        $qb = $connection->createQueryBuilder();

        $result = $qb->select('*')
            ->from('users')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery();

        return $result->fetchAssociative() ?: null;
    }
}
