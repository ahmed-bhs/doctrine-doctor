<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Metadata;

use AhmedBhs\DoctrineDoctor\Metadata\EntityManagerMetadataDecorator;
use AhmedBhs\DoctrineDoctor\Metadata\EntityMetadataProvider;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Integration test to verify that the transparent filtering works.
 *
 * This tests that when analyzers use EntityManager, they automatically
 * get filtered metadata without any code changes.
 */
class TransparentFilteringIntegrationTest extends TestCase
{
    public function testEntityManagerDecoratorFiltersMetadataTransparently(): void
    {
        // Create original EntityManager
        $originalEM = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/BidirectionalConsistencyTest',
        ]);

        // Create EntityMetadataProvider
        $metadataProvider = new EntityMetadataProvider($originalEM, excludeVendorEntities: true);

        // Wrap with decorator
        $decoratedEM = new EntityManagerMetadataDecorator($originalEM, $metadataProvider);

        // Test that decorated EM returns filtered metadata
        $filteredMetadata = $decoratedEM->getMetadataFactory()->getAllMetadata();

        // Should have metadata (not empty)
        $this->assertNotEmpty($filteredMetadata);

        // All metadata should be from non-vendor paths
        foreach ($filteredMetadata as $metadata) {
            $filename = $metadata->getReflectionClass()->getFileName();
            $normalizedPath = str_replace('\\', '/', $filename);

            $this->assertStringNotContainsString(
                '/vendor/',
                $normalizedPath,
                sprintf('Entity %s is from vendor directory', $metadata->getName())
            );
        }
    }

    public function testDecoratorPassesThroughOtherMethodsToOriginalEM(): void
    {
        $originalEM = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/BidirectionalConsistencyTest',
        ]);

        $metadataProvider = new EntityMetadataProvider($originalEM, excludeVendorEntities: true);
        $decoratedEM = new EntityManagerMetadataDecorator($originalEM, $metadataProvider);

        // Test that other methods are passed through
        $this->assertSame($originalEM->getConnection(), $decoratedEM->getConnection());
        $this->assertSame($originalEM->getConfiguration(), $decoratedEM->getConfiguration());
        $this->assertSame($originalEM->isOpen(), $decoratedEM->isOpen());
    }

    public function testAnalyzerReceivesFilteredMetadataAutomatically(): void
    {
        // Create original EntityManager with test entities
        $originalEM = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/BidirectionalConsistencyTest',
        ]);

        // Create metadata provider
        $metadataProvider = new EntityMetadataProvider($originalEM, excludeVendorEntities: true);

        // Create decorated EM (this is what analyzers will receive via DI)
        $decoratedEM = new EntityManagerMetadataDecorator($originalEM, $metadataProvider);

        // Simulate what an analyzer does: call getAllMetadata()
        $metadata = $decoratedEM->getMetadataFactory()->getAllMetadata();

        // Should receive filtered metadata automatically
        $this->assertNotEmpty($metadata);

        // Verify no vendor entities
        foreach ($metadata as $meta) {
            $filename = $meta->getReflectionClass()->getFileName();
            $this->assertStringNotContainsString('/vendor/', $filename);
        }
    }
}
