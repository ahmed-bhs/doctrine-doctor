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
 * Test fixture: Account with ManyToOne cascade="remove" to Settings.
 *
 * The inverse side (Settings) has OneToOne mapping (not OneToMany),
 * which indicates this is a bidirectional 1:1 relationship.
 *
 * cascade="remove" is APPROPRIATE here because:
 * - Inverse is OneToOne (not OneToMany)
 * - Settings is composition/configuration data
 * - Deleting Account should delete its Settings
 *
 * This should NOT be flagged as an issue.
 */
#[ORM\Entity]
#[ORM\Table(name: 'account_with_inverse_one_to_one')]
class AccountWithInverseOneToOne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    /**
     * ManyToOne with cascade="remove" where inverse is OneToOne.
     * This is ACCEPTABLE - inverse OneToOne indicates 1:1 composition.
     */
    #[ORM\ManyToOne(targetEntity: SettingsWithInverseOneToOne::class, inversedBy: 'account', cascade: ['remove'])]
    #[ORM\JoinColumn(name: 'settings_id', nullable: true)]
    private ?SettingsWithInverseOneToOne $settings = null;

    public function __construct(string $email)
    {
        $this->email = $email;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getSettings(): ?SettingsWithInverseOneToOne
    {
        return $this->settings;
    }

    public function setSettings(?SettingsWithInverseOneToOne $settings): void
    {
        $this->settings = $settings;
    }
}
