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
 * Test entity with insecure random number generation.
 * Used to test InsecureRandomAnalyzer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'entities_insecure_random')]
class EntityWithInsecureRandom
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $apiToken;

    #[ORM\Column(type: 'string', length: 255)]
    private string $resetToken;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * VULNERABLE: Uses rand() for API token generation.
     */
    public function generateApiToken(): string
    {
        $this->apiToken = bin2hex(rand(0, PHP_INT_MAX)); // INSECURE!
        return $this->apiToken;
    }

    /**
     * VULNERABLE: Uses mt_rand() for reset token.
     */
    public function generateResetToken(): string
    {
        $this->resetToken = md5(mt_rand()); // INSECURE! Even with md5
        return $this->resetToken;
    }

    /**
     * VULNERABLE: Uses uniqid() for secret key.
     */
    public function generateSecretKey(): string
    {
        return uniqid('secret_', true); // INSECURE! Predictable
    }

    /**
     * VULNERABLE: Uses time() for password reset.
     */
    public function generatePasswordResetCode(): string
    {
        return md5(time() . rand()); // INSECURE! Weak hash of weak random
    }

    /**
     * VULNERABLE: Uses microtime() for CSRF token.
     */
    public function generateCsrfToken(): string
    {
        return md5(microtime(true)); // INSECURE! Predictable timestamp
    }

    /**
     * VULNERABLE: Uses mt_rand() for session ID.
     */
    public function generateSessionId(): string
    {
        return bin2hex(mt_rand()); // INSECURE!
    }

    /**
     * VULNERABLE: Uses rand() for verification code.
     */
    public function generateVerificationCode(): int
    {
        return rand(100000, 999999); // INSECURE! For verification codes
    }

    /**
     * SECURE: Uses random_bytes() - should NOT be flagged.
     */
    public function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32)); // SECURE!
    }

    /**
     * SECURE: Uses random_int() - should NOT be flagged.
     */
    public function generateSecureCode(): int
    {
        return random_int(100000, 999999); // SECURE!
    }

    /**
     * NOT SECURITY SENSITIVE: Uses rand() but not for security.
     * Should NOT be flagged (no sensitive context).
     */
    public function generateRandomColor(): string
    {
        $colors = ['red', 'blue', 'green', 'yellow'];
        return $colors[rand(0, count($colors) - 1)]; // OK for non-security
    }
}
