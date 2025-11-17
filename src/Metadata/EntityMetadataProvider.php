<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Provides entity metadata with optional filtering of vendor entities.
 *
 * This service wraps EntityManager's metadata factory and provides a clean,
 * cached interface for analyzers. Results are cached per request for performance.
 *
 * Usage in analyzers:
 * ```php
 * $allMetadata = $this->metadataProvider->getAllMetadata(); // Already filtered!
 * ```
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class EntityMetadataProvider
{
    /** @var ClassMetadata[]|null Cached filtered metadata */
    private ?array $cachedFilteredMetadata = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly bool $excludeVendorEntities = true,
    ) {
    }

    /**
     * Get all entity metadata, excluding vendor entities if configured.
     * Results are cached for the request lifecycle (important for runtime performance).
     *
     * @return ClassMetadata[]
     */
    public function getAllMetadata(): array
    {
        // Return cached result if available
        if (null !== $this->cachedFilteredMetadata) {
            return $this->cachedFilteredMetadata;
        }

        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        // If filtering disabled, cache and return all
        if (!$this->excludeVendorEntities) {
            $this->cachedFilteredMetadata = $allMetadata;

            return $allMetadata;
        }

        // Filter vendor entities and cache result
        $this->cachedFilteredMetadata = array_values(
            array_filter($allMetadata, fn ($metadata) => !$this->isVendorEntity($metadata)),
        );

        return $this->cachedFilteredMetadata;
    }

    /**
     * Get metadata for a specific entity (no filtering applied).
     */
    public function getMetadataFor(string $className): ClassMetadata
    {
        /** @var class-string $className */
        return $this->entityManager->getMetadataFactory()->getMetadataFor($className);
    }

    /**
     * Clear cache - useful for tests.
     */
    public function clearCache(): void
    {
        $this->cachedFilteredMetadata = null;
    }

    /**
     * Check if entity is from vendor directory using path-based detection.
     * More reliable than namespace patterns.
     */
    private function isVendorEntity(ClassMetadata $metadata): bool
    {
        try {
            $reflectionClass = $metadata->getReflectionClass();

            if (null === $reflectionClass) {
                return false;
            }

            $filename = $reflectionClass->getFileName();

            if (false === $filename) {
                return false;
            }

            // Normalize path separators for cross-platform compatibility
            $normalizedPath = str_replace('\\', '/', $filename);

            // Simple and fast: check if path contains /vendor/
            return str_contains($normalizedPath, '/vendor/');
        } catch (\Throwable) {
            // If we can't determine, include it (safe default)
            return false;
        }
    }
}
