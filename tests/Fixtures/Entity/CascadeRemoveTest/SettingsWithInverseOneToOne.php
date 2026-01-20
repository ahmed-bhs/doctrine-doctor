<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeRemoveTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture: Settings with inverse OneToOne mapping.
 *
 * This proves the relationship is 1:1, not ManyToOne.
 */
#[ORM\Entity]
#[ORM\Table(name: 'settings_with_inverse_one_to_one')]
class SettingsWithInverseOneToOne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $theme;

    /**
     * IMPORTANT: This is OneToOne (not OneToMany).
     * This proves Account->Settings is 1:1, not ManyToOne.
     */
    #[ORM\OneToOne(targetEntity: AccountWithInverseOneToOne::class, mappedBy: 'settings')]
    private ?AccountWithInverseOneToOne $account = null;

    public function __construct(string $theme)
    {
        $this->theme = $theme;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function getAccount(): ?AccountWithInverseOneToOne
    {
        return $this->account;
    }

    public function setAccount(?AccountWithInverseOneToOne $account): void
    {
        $this->account = $account;
    }
}
