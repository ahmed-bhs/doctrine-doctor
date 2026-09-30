<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'books_on_shelf')]
class BookOnShelf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShelfWithDbCascade::class, inversedBy: 'books')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShelfWithDbCascade $shelf;

    public function __construct(ShelfWithDbCascade $shelf)
    {
        $this->shelf = $shelf;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShelf(): ShelfWithDbCascade
    {
        return $this->shelf;
    }
}
