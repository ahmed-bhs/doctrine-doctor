<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Metadata;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transparent decorator for EntityManager that returns filtered metadata.
 *
 * This decorator:
 * - Overrides ONLY getMetadataFactory() to return filtered metadata
 * - Delegates everything else to the original EntityManager
 * - Is completely transparent to analyzers (no code changes needed)
 *
 * Thanks to Symfony service decoration + binding, analyzers automatically
 * receive this decorated EM instead of the original one.
 */
class EntityManagerMetadataDecorator extends EntityManagerDecorator
{
    private ?FilteredClassMetadataFactory $filteredFactory = null;

    public function __construct(
        EntityManagerInterface $decoratedEntityManager,
        private readonly EntityMetadataProvider $metadataProvider,
    ) {
        parent::__construct($decoratedEntityManager);
    }

    /**
     * Override to return filtered metadata factory.
     * This is the ONLY method we override - everything else is delegated.
     *
     * @phpstan-ignore-next-line Return type intentionally more specific than interface
     */
    public function getMetadataFactory(): \Doctrine\ORM\Mapping\ClassMetadataFactory
    {
        if (null === $this->filteredFactory) {
            $originalFactory = $this->wrapped->getMetadataFactory();
            $this->filteredFactory = new FilteredClassMetadataFactory(
                $originalFactory,
                $this->metadataProvider,
            );
        }

        return $this->filteredFactory;
    }
}
