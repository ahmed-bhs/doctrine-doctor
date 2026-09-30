<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Parent relying on the database to delete its books (onDelete=CASCADE, no ORM
 * cascade). The books have no remove callback: nothing is skipped.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shelves_with_db_cascade')]
class ShelfWithDbCascade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** @var Collection<int, BookOnShelf> */
    #[ORM\OneToMany(targetEntity: BookOnShelf::class, mappedBy: 'shelf')]
    private Collection $books;

    public function __construct()
    {
        $this->books = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return Collection<int, BookOnShelf> */
    public function getBooks(): Collection
    {
        return $this->books;
    }
}
