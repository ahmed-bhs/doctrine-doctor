<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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

    public function test_detects_one_to_one_composition_with_orphan_removal(): void
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
        self::assertTrue($result, 'OneToOne with orphanRemoval should be composition');
    }

    public function test_detects_one_to_one_composition_with_cascade_remove(): void
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
        self::assertTrue($result, 'OneToOne with cascade remove should be composition');
    }

    public function test_rejects_one_to_one_without_composition_indicators(): void
    {
        // Given: A OneToOne mapping without composition indicators
        $mapping = [
            'type' => ClassMetadata::ONE_TO_ONE,
            'targetEntity' => 'RelatedEntity',
        ];

        // When: We check if it's a composition
        $result = $this->detector->isOneToOneComposition($mapping);

        // Then: It should NOT be detected as composition
        self::assertFalse($result, 'OneToOne without indicators should not be composition');
    }

    // ========================================================================
    // OneToMany Tests
    // ========================================================================

    public function test_detects_one_to_many_composition_with_orphan_removal(): void
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
        self::assertTrue($result, 'OneToMany with orphanRemoval should be composition');
    }

    public function test_detects_one_to_many_composition_by_child_name(): void
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
        self::assertTrue($result, 'OneToMany with suggestive child name should be composition');
    }

    public function test_rejects_one_to_many_without_cascade_remove(): void
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
        self::assertFalse($result, 'OneToMany without cascade remove should not be composition');
    }

    // ========================================================================
    // ManyToOne Tests (Edge Cases)
    // ========================================================================

    public function test_detects_many_to_one_with_unique_constraint_as_composition(): void
    {
        // Given: A ManyToOne with unique constraint on FK (effectively 1:1)
        $className = 'PaymentMethod';
        /** @phpstan-ignore-next-line Test uses string literal for simplicity */
        $metadata = new ClassMetadata($className);
        $metadata->table = [
            'name' => 'payment_method',
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
        self::assertTrue($result, 'ManyToOne with unique FK should be 1:1 composition');
    }

    // ========================================================================
    // Child Name Pattern Tests
    // ========================================================================

    /**
     * @dataProvider compositionChildNameProvider
     */
    public function test_detects_composition_child_names(string $entityName, bool $expectedResult): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('Parent');

        $mapping = [
            'type' => ClassMetadata::ONE_TO_MANY,
            'cascade' => ['remove'],
            'targetEntity' => $entityName,
        ];

        $result = $this->detector->isOneToManyComposition($metadata, $mapping);

        self::assertSame(
            $expectedResult,
            $result,
            sprintf('Entity "%s" should %s be detected as composition', $entityName, $expectedResult ? '' : 'NOT'),
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
