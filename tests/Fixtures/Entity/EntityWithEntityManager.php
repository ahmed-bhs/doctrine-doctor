<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test entity with EntityManager anti-pattern.
 * Used to test EntityManagerInEntityAnalyzer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'entities_with_em')]
class EntityWithEntityManager
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * ANTI-PATTERN: EntityManager in constructor.
     */
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $em,
    ) {
    }

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
     * ANTI-PATTERN: Flush in entity method.
     */
    public function save(): void
    {
        $this->em->persist($this);
        $this->em->flush(); // Persistence in domain!
    }

    /**
     * ANTI-PATTERN: Persist in entity method.
     */
    public function createRelated(): void
    {
        $related = new self($this->em);
        $this->em->persist($related);
    }
}
