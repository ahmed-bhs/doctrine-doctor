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
 * Test fixture: GatewayConfig that is exclusively owned by PaymentMethod.
 *
 * This is a composition/value object pattern:
 * - Only referenced by ONE entity type (PaymentMethod)
 * - Contains configuration data specific to one payment method
 * - No independent lifecycle (deleted when PaymentMethod is deleted)
 *
 * This should NOT trigger cascade="remove" warnings when referenced
 * by PaymentMethod because it's a dependent entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gateway_config_one_to_one')]
class GatewayConfigOneToOne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $factoryName;

    #[ORM\Column(type: 'string', length: 255)]
    private string $gatewayName;

    #[ORM\Column(type: 'json')]
    private array $config = [];

    public function __construct(string $factoryName, string $gatewayName)
    {
        $this->factoryName = $factoryName;
        $this->gatewayName = $gatewayName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFactoryName(): string
    {
        return $this->factoryName;
    }

    public function getGatewayName(): string
    {
        return $this->gatewayName;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }
}
