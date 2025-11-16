<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CompositionRelationshipDetector;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CompositionRelationshipDetector.
 *
 * These tests validate the various heuristics used to detect
 * composition vs aggregation relationships.
 */
final class CompositionRelationshipDetectorTest extends TestCase
{
    private CompositionRelationshipDetector $detector;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->detector = new CompositionRelationshipDetector($this->entityManager);
    }

    // ========================================================================
    // OneToOne Tests
    // ========================================================================

    public function testDetectsOneToOneCompositionWithOrphanRemoval(): void
    {
        // Given: A OneToOne mapping with orphanRemoval=true
        $mapping = [
            'type' => ClassMetadata::ONE_TO_ONE,
            'orphanRemoval' => true,
            'targetEntity' => 'AvatarImage',
        ];

        // When: We check if it's a composition
        $result = $this->detector->isOneToOneComposition($mapping);

        // Then: It should be detected as composition
        $this->assertTrue($result, 'OneToOne with orphanRemoval should be composition');
    }

    public function testDetectsOneToOneCompositionWithCascadeRemove(): void
    {
        // Given: A OneToOne mapping with cascade remove
        $mapping = [
            'type' => ClassMetadata::ONE_TO_ONE,
            'cascade' => ['remove'],
            'targetEntity' => 'Profile',
        ];

        // When: We check if it's a composition
        $result = $this->detector->isOneToOneComposition($mapping);

        // Then: It should be detected as composition
        $this->assertTrue($result, 'OneToOne with cascade remove should be composition');
    }

    public function testRejectsOneToOneWithoutCompositionIndicators(): void
    {
        // Given: A OneToOne mapping without composition indicators
        $mapping = [
            'type' => ClassMetadata::ONE_TO_ONE,
            'targetEntity' => 'RelatedEntity',
        ];

        // When: We check if it's a composition
        $result = $this->detector->isOneToOneComposition($mapping);

        // Then: It should NOT be detected as composition
        $this->assertFalse($result, 'OneToOne without indicators should not be composition');
    }

    // ========================================================================
    // OneToMany Tests
    // ========================================================================

    public function testDetectsOneToManyCompositionWithOrphanRemoval(): void
    {
        // Given: A OneToMany mapping with orphanRemoval
        $metadata = $this->createMock(ClassMetadata::class);
        $mapping = [
            'type' => ClassMetadata::ONE_TO_MANY,
            'orphanRemoval' => true,
            'targetEntity' => 'OrderItem',
        ];

        // When: We check if it's a composition
        $result = $this->detector->isOneToManyComposition($metadata, $mapping);

        // Then: It should be detected as composition
        $this->assertTrue($result, 'OneToMany with orphanRemoval should be composition');
    }

    public function testDetectsOneToManyCompositionByChildName(): void
    {
        // Given: A OneToMany with cascade remove and suggestive child name
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('Order');

        $mapping = [
            'type' => ClassMetadata::ONE_TO_MANY,
            'cascade' => ['remove'],
            'targetEntity' => 'App\\Entity\\OrderItem', // Name suggests composition
        ];

        // When: We check if it's a composition
        $result = $this->detector->isOneToManyComposition($metadata, $mapping);

        // Then: It should be detected as composition
        $this->assertTrue($result, 'OneToMany with suggestive child name should be composition');
    }

    public function testRejectsOneToManyWithoutCascadeRemove(): void
    {
        // Given: A OneToMany without cascade remove
        $metadata = $this->createMock(ClassMetadata::class);
        $mapping = [
            'type' => ClassMetadata::ONE_TO_MANY,
            'targetEntity' => 'SomeEntity',
        ];

        // When: We check if it's a composition
        $result = $this->detector->isOneToManyComposition($metadata, $mapping);

        // Then: It should NOT be detected as composition
        $this->assertFalse($result, 'OneToMany without cascade remove should not be composition');
    }

    // ========================================================================
    // ManyToOne Tests (Edge Cases)
    // ========================================================================

    public function testDetectsManyToOneWithUniqueConstraintAsComposition(): void
    {
        // Given: A ManyToOne with unique constraint on FK (effectively 1:1)
        $metadata = new ClassMetadata('PaymentMethod');
        $metadata->table = [
            'uniqueConstraints' => [
                ['columns' => ['gateway_config_id']], // Unique FK = 1:1
            ],
        ];

        $association = [
            'type' => ClassMetadata::MANY_TO_ONE,
            'targetEntity' => 'GatewayConfig',
            'joinColumns' => [
                ['name' => 'gateway_config_id', 'referencedColumnName' => 'id'],
            ],
        ];

        // When: We check if it's actually a 1:1 composition
        $result = $this->detector->isManyToOneActuallyOneToOneComposition($metadata, $association);

        // Then: It should be detected as composition
        $this->assertTrue($result, 'ManyToOne with unique FK should be 1:1 composition');
    }

    // ========================================================================
    // Child Name Pattern Tests
    // ========================================================================

    /**
     * @dataProvider compositionChildNameProvider
     */
    public function testDetectsCompositionChildNames(string $entityName, bool $expectedResult): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('Parent');

        $mapping = [
            'type' => ClassMetadata::ONE_TO_MANY,
            'cascade' => ['remove'],
            'targetEntity' => $entityName,
        ];

        $result = $this->detector->isOneToManyComposition($metadata, $mapping);

        $this->assertSame(
            $expectedResult,
            $result,
            sprintf('Entity "%s" should %s be detected as composition', $entityName, $expectedResult ? '' : 'NOT')
        );
    }

    public static function compositionChildNameProvider(): array
    {
        return [
            // Composition patterns
            ['OrderItem', true],
            ['AddressLine', true],
            ['CartEntry', true],
            ['InvoiceDetail', true],
            ['ShippingPart', true],
            ['ProductComponent', true],

            // Independent entity patterns
            ['User', false],
            ['Customer', false],
            ['Product', false],
            ['Category', false],
        ];
    }
}
