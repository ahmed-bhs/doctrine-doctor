<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child class that extends BaseOrderWithInit.
 * Tests that analyzer correctly detects parent class initialization.
 *
 * This is similar to Sylius: App\Entity\Order extends Core\Model\Order extends Order\Model\Order
 * where the base class initializes collections.
 *
 * This should NOT trigger warnings because parent constructor initializes collections.
 */
#[ORM\Entity]
#[ORM\Table(name: 'child_order_with_parent_init')]
class ChildOrderWithParentInit extends BaseOrderWithInit
{
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $customerNotes = null;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'post')]
    private Collection $comments;

    public function __construct()
    {
        // Call parent constructor which initializes items and tags
        parent::__construct();

        // Initialize our own collection
        $this->comments = new ArrayCollection();
    }

    public function getCustomerNotes(): ?string
    {
        return $this->customerNotes;
    }

    public function setCustomerNotes(?string $notes): void
    {
        $this->customerNotes = $notes;
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }
}
