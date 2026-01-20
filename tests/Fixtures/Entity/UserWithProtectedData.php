<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Ignore;

/**
 * Test entity with properly protected sensitive data.
 * Used to test SensitiveDataExposureAnalyzer (should NOT trigger issues).
 */
#[ORM\Entity]
#[ORM\Table(name: 'users_protected')]
class UserWithProtectedData implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $username;

    // SECURE: Password protected with #[Ignore]
    #[ORM\Column(type: 'string', length: 255)]
    #[Ignore]
    private string $password;

    // SECURE: API token protected with #[Ignore]
    #[ORM\Column(type: 'string', length: 64)]
    #[Ignore]
    private string $apiToken;

    /**
     * SECURE: Only exposes non-sensitive data.
     */
    public function __toString(): string
    {
        return sprintf('User #%d', $this->id ?? 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getApiToken(): string
    {
        return $this->apiToken;
    }

    public function setApiToken(string $apiToken): void
    {
        $this->apiToken = $apiToken;
    }

    /**
     * SECURE: Does NOT expose sensitive fields.
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            // password and apiToken are NOT included
        ];
    }

    /**
     * SECURE: Does NOT expose sensitive fields.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            // password and apiToken are NOT included
        ];
    }
}
