<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\TraitCollectionInitializationDetector;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for TraitCollectionInitializationDetector.
 *
 * These tests validate that the detector correctly identifies collection
 * initializations in traits, including Sylius-style constructor aliasing.
 */
final class TraitCollectionInitializationDetectorTest extends TestCase
{
    private TraitCollectionInitializationDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new TraitCollectionInitializationDetector();
    }

    public function test_detects_direct_collection_initialization_in_trait(): void
    {
        // Given: A class using a trait that initializes a collection
        $reflection = new ReflectionClass(ClassUsingTraitWithInitialization::class);

        // When: We check if 'items' is initialized in traits
        $result = $this->detector->isCollectionInitializedInTraits($reflection, 'items');

        // Then: It should be detected
        self::assertTrue($result, 'Should detect collection initialization in trait');
    }

    public function test_detects_sylius_style_constructor_aliasing(): void
    {
        // Given: A class using Sylius-style constructor aliasing
        $reflection = new ReflectionClass(SyliusStyleClass::class);

        // When: We check if 'translations' is initialized
        $result = $this->detector->isCollectionInitializedInTraits($reflection, 'translations');

        // Then: It should be detected even though it's called via alias
        self::assertTrue($result, 'Should detect Sylius-style initialization via alias');
    }

    public function test_detects_nested_traits(): void
    {
        // Given: A class using a trait that itself uses another trait
        $reflection = new ReflectionClass(ClassWithNestedTraits::class);

        // When: We check for initialization in nested trait
        $result = $this->detector->isCollectionInitializedInTraits($reflection, 'nestedItems');

        // Then: It should be detected in the nested trait
        self::assertTrue($result, 'Should detect initialization in nested traits');
    }

    public function test_returns_false_when_not_initialized(): void
    {
        // Given: A class using a trait that doesn't initialize the collection
        $reflection = new ReflectionClass(ClassWithUninitializedCollection::class);

        // When: We check for initialization
        $result = $this->detector->isCollectionInitializedInTraits($reflection, 'items');

        // Then: It should return false
        self::assertFalse($result, 'Should return false when collection is not initialized');
    }

    public function test_returns_false_for_non_existent_field(): void
    {
        // Given: A class using a trait
        $reflection = new ReflectionClass(ClassUsingTraitWithInitialization::class);

        // When: We check for a field that doesn't exist
        $result = $this->detector->isCollectionInitializedInTraits($reflection, 'nonExistentField');

        // Then: It should return false
        self::assertFalse($result, 'Should return false for non-existent field');
    }

    public function test_ignores_comments_in_trait_code(): void
    {
        // Given: A trait with initialization in comments
        $reflection = new ReflectionClass(ClassWithCommentedInitialization::class);

        // When: We check for initialization
        $result = $this->detector->isCollectionInitializedInTraits($reflection, 'items');

        // Then: It should return false (comments are ignored)
        self::assertFalse($result, 'Should ignore commented-out initializations');
    }
}

// ============================================================================
// Test Fixtures
// ============================================================================

/**
 * Trait that directly initializes a collection in its constructor.
 */
trait TraitWithDirectInitialization
{
    /** @var mixed */
    protected $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }
}

class ClassUsingTraitWithInitialization
{
    use TraitWithDirectInitialization;
}

/**
 * Sylius-style trait with constructor aliasing.
 */
trait TranslatableTrait
{
    /** @var mixed */
    protected $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }
}

class SyliusStyleClass
{
    use TranslatableTrait {
        __construct as private initializeTranslationsCollection;
    }

    public function __construct()
    {
        $this->initializeTranslationsCollection();
        // Other initialization...
    }
}

/**
 * Nested traits scenario.
 */
trait BaseCollectionTrait
{
    /** @var mixed */
    protected $nestedItems;

    public function __construct()
    {
        $this->nestedItems = new ArrayCollection();
    }
}

trait MiddleTrait
{
    use BaseCollectionTrait;
}

class ClassWithNestedTraits
{
    use MiddleTrait;
}

/**
 * Trait without initialization.
 */
trait TraitWithoutInitialization
{
    /** @var mixed */
    protected $items;

    public function __construct()
    {
        // No initialization here
    }
}

class ClassWithUninitializedCollection
{
    use TraitWithoutInitialization;
}

/**
 * Trait with initialization only in comments.
 */
trait TraitWithCommentedCode
{
    /** @var mixed */
    protected $items;

    public function __construct()
    {
        // TODO: $this->items = new ArrayCollection();
        /* Future implementation:
         * $this->items = new ArrayCollection();
         */
    }
}

class ClassWithCommentedInitialization
{
    use TraitWithCommentedCode;
}
