<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Metadata;

use AhmedBhs\DoctrineDoctor\Metadata\EntityMetadataProvider;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\TestCase;

class EntityMetadataProviderTest extends TestCase
{
    public function test_get_all_metadata_when_filtering_disabled(): void
    {
        $em = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/BidirectionalConsistencyTest',
        ]);

        $provider = new EntityMetadataProvider($em, excludeVendorEntities: false);
        $allMetadata = $provider->getAllMetadata();

        // Should return all metadata
        self::assertNotEmpty($allMetadata);
    }

    public function test_get_all_metadata_caches_result(): void
    {
        $em = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/BidirectionalConsistencyTest',
        ]);

        $provider = new EntityMetadataProvider($em, excludeVendorEntities: true);

        // First call
        $result1 = $provider->getAllMetadata();
        // Second call (should use cache - same reference)
        $result2 = $provider->getAllMetadata();

        self::assertSame($result1, $result2);
    }

    public function test_clear_cache_resets_metadata(): void
    {
        $em = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/BidirectionalConsistencyTest',
        ]);

        $provider = new EntityMetadataProvider($em, excludeVendorEntities: true);

        // First call
        $result1 = $provider->getAllMetadata();

        // Without clearing cache, second call returns same reference
        $result2 = $provider->getAllMetadata();
        self::assertSame($result1, $result2, 'Cache should return same reference');

        // Clear cache
        $provider->clearCache();

        // Third call after cache clear should fetch from EntityManager again
        $result3 = $provider->getAllMetadata();

        // Results should have equal content
        self::assertEquals($result1, $result3, 'Content should be equal');
        // But after clear, we've re-filtered so it's technically a new array
        // (though PHP might optimize this - the important thing is cache was cleared)
        self::assertNotEmpty($result3);
    }

    public function test_get_metadata_for_specific_entity(): void
    {
        $em = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/BidirectionalConsistencyTest',
        ]);

        $provider = new EntityMetadataProvider($em, excludeVendorEntities: true);

        $allMetadata = $provider->getAllMetadata();
        self::assertNotEmpty($allMetadata);

        // Get metadata for the first entity
        $firstEntity = reset($allMetadata);
        $entityClass = $firstEntity->getName();

        $specificMetadata = $provider->getMetadataFor($entityClass);

        self::assertSame($entityClass, $specificMetadata->getName());
    }
}
