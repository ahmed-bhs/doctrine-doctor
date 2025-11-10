<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * User entity with sensitive data - FOR TESTING ONLY.
 * This entity intentionally exposes sensitive fields to test SensitiveDataExposureAnalyzer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users_with_sensitive_data')]
class UserWithSensitiveData implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    /**
     * BAD: Password stored in plain text without protection annotation.
     * This is intentional for testing.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $password;

    /**
     * BAD: API key without protection.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $apiKey = null;

    /**
     * BAD: Secret token without protection.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $secretToken = null;

    public function __construct(string $username, string $email, string $password)
    {
        $this->username = $username;
        $this->email = $email;
        $this->password = $password;
    }

    /**
     * BAD: This exposes ALL fields including password!
     * This is intentional for testing SensitiveDataExposureAnalyzer.
     */
    public function __toString(): string
    {
        return json_encode($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function getSecretToken(): ?string
    {
        return $this->secretToken;
    }

    public function setSecretToken(?string $secretToken): self
    {
        $this->secretToken = $secretToken;
        return $this;
    }

    /**
     * BAD: This exposes password in JSON serialization!
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'password' => $this->password, // SENSITIVE!
            'apiKey' => $this->apiKey, // SENSITIVE!
            'secretToken' => $this->secretToken, // SENSITIVE!
        ];
    }

    /**
     * BAD: This exposes sensitive fields in array conversion!
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'password' => $this->password, // SENSITIVE!
            'apiKey' => $this->apiKey, // SENSITIVE!
        ];
    }
}
