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
 * Test fixture: PaymentMethod with ManyToOne cascade="all" to GatewayConfig.
 *
 * This is a 1:1 composition relationship even though technically ManyToOne:
 * - Each PaymentMethod has its own unique GatewayConfig
 * - GatewayConfig is configuration data (value object pattern)
 * - cascade="remove" is APPROPRIATE here
 *
 * This should NOT be flagged as an issue because:
 * 1. GatewayConfig is exclusively owned by PaymentMethod
 * 2. No shared references to GatewayConfig
 * 3. It's a composition relationship (config is part of payment method)
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_method_one_to_one')]
class PaymentMethodOneToOne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $code;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    /**
     * ManyToOne with cascade="all" to composition entity.
     * This is ACCEPTABLE because GatewayConfig is owned exclusively.
     */
    #[ORM\ManyToOne(targetEntity: GatewayConfigOneToOne::class, cascade: ['all'])]
    #[ORM\JoinColumn(name: 'gateway_config_id', nullable: true, onDelete: 'SET NULL')]
    private ?GatewayConfigOneToOne $gatewayConfig = null;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getGatewayConfig(): ?GatewayConfigOneToOne
    {
        return $this->gatewayConfig;
    }

    public function setGatewayConfig(?GatewayConfigOneToOne $gatewayConfig): void
    {
        $this->gatewayConfig = $gatewayConfig;
    }
}
