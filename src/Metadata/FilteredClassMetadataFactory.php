<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Metadata;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory as BaseClassMetadataFactory;

/**
 * Proxy around Doctrine's ClassMetadataFactory that filters out vendor entities.
 *
 * This proxy extends Doctrine's concrete ClassMetadataFactory and overrides getAllMetadata()
 * to delegate filtering to EntityMetadataProvider.
 *
 * Performance: Filtering is cached by EntityMetadataProvider per request.
 */
class FilteredClassMetadataFactory extends BaseClassMetadataFactory
{
    public function __construct(
        private readonly BaseClassMetadataFactory $decoratedFactory,
        private readonly EntityMetadataProvider $metadataProvider,
    ) {
        // Don't call parent constructor - we're wrapping, not extending functionality
    }

    /**
     * Returns all metadata, excluding vendor entities if configured.
     * Delegates to EntityMetadataProvider which handles caching.
     *
     * @return ClassMetadata[]
     */
    public function getAllMetadata(): array
    {
        // Delegate to EntityMetadataProvider (which has built-in caching)
        return $this->metadataProvider->getAllMetadata();
    }

    // ========================================================================
    // Passthrough methods to decorated factory
    // ========================================================================

    public function getMetadataFor(string $className): ClassMetadata
    {
        return $this->decoratedFactory->getMetadataFor($className);
    }

    public function isTransient(string $className): bool
    {
        return $this->decoratedFactory->isTransient($className);
    }

    public function setReflectionService(\Doctrine\Persistence\Mapping\ReflectionService $reflectionService): void
    {
        $this->decoratedFactory->setReflectionService($reflectionService);
    }

    public function hasMetadataFor(string $className): bool
    {
        return $this->decoratedFactory->hasMetadataFor($className);
    }

    public function setMetadataFor(string $className, $class): void
    {
        $this->decoratedFactory->setMetadataFor($className, $class);
    }
}
