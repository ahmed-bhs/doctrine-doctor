<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ForeignKeyTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with fields that LOOK like foreign keys but are NOT.
 * These should NOT be detected as issues (false positives to avoid).
 */
#[ORM\Entity]
class EntityWithFalsePositives
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // CONFIGURATION FIELDS (should NOT be detected)
    #[ORM\Column(type: 'integer')]
    private int $orderExpirationDays; // Our original false positive

    #[ORM\Column(type: 'integer')]
    private int $userAge; // Age is a property, not FK

    #[ORM\Column(type: 'integer')]
    private int $productCount; // Count is a metric, not FK

    #[ORM\Column(type: 'integer')]
    private int $customerNumber; // Number is an identifier, not FK

    #[ORM\Column(type: 'integer')]
    private int $orderCount; // Count is metric

    #[ORM\Column(type: 'integer')]
    private int $orderTotal; // Total is amount

    #[ORM\Column(type: 'integer')]
    private int $userStatus; // Status is enum-like

    #[ORM\Column(type: 'integer')]
    private int $productPrice; // Price is value

    #[ORM\Column(type: 'integer')]
    private int $accountBalance; // Balance is amount

    #[ORM\Column(type: 'integer')]
    private int $teamSize; // Size is measurement

    #[ORM\Column(type: 'integer')]
    private int $position; // Position is ordering

    #[ORM\Column(type: 'integer')]
    private int $version; // Version is metadata

    #[ORM\Column(type: 'integer')]
    private int $timeout; // Timeout is configuration

    #[ORM\Column(type: 'integer')]
    private int $expirationPeriod; // Expiration is time-based

    #[ORM\Column(type: 'integer')]
    private int $configurationKey; // Configuration value

    #[ORM\Column(type: 'integer')]
    private int $statusCode; // Status code, not FK

    #[ORM\Column(type: 'integer')]
    private int $limit; // Limit is constraint

    #[ORM\Column(type: 'integer')]
    private int $maxAmount; // Amount is value

    #[ORM\Column(type: 'integer')]
    private int $discountPercentage; // Percentage is rate

    #[ORM\Column(type: 'integer')]
    private int $delay; // Delay is timing

    // MORE COMPOUND EXAMPLES
    #[ORM\Column(type: 'integer')]
    private int $orderProcessingTime; // Compound with time

    #[ORM\Column(type: 'integer')]
    private int $userSessionTimeout; // Compound with timeout

    #[ORM\Column(type: 'integer')]
    private int $productInventoryCount; // Compound with count

    #[ORM\Column(type: 'integer')]
    private int $customerLifetimeValue; // Compound with value

    #[ORM\Column(type: 'integer')]
    private int $orderValidationCode; // Compound with code
}
