<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test entity with SQL injection vulnerabilities in its methods.
 * Used to test SQLInjectionInRawQueriesAnalyzer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'products_vulnerable')]
class EntityWithVulnerableMethods
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * VULNERABLE: String concatenation in entity method.
     */
    public function loadRelatedDataUnsafe(EntityManagerInterface $em, string $category): array
    {
        $connection = $em->getConnection();
        $sql = "SELECT * FROM categories WHERE name = '" . $category . "'";

        return $connection->executeQuery($sql)->fetchAllAssociative();
    }

    /**
     * VULNERABLE: Variable interpolation in entity method.
     */
    public function updateStatusUnsafe(Connection $connection, int $status): void
    {
        $sql = "UPDATE products_vulnerable SET status = {$status} WHERE id = {$this->id}";
        $connection->executeStatement($sql);
    }

    /**
     * SECURE: Parameterized query (should NOT be flagged).
     */
    public function loadRelatedDataSafe(EntityManagerInterface $em, string $category): array
    {
        $connection = $em->getConnection();
        $sql = 'SELECT * FROM categories WHERE name = :name';

        return $connection->executeQuery($sql, ['name' => $category])->fetchAllAssociative();
    }
}
