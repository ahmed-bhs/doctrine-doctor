<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for PhpCodeParser.
 *
 * These tests validate that the PHP Parser correctly identifies
 * collection initializations without the fragility of regex.
 */
final class PhpCodeParserTest extends TestCase
{
    private PhpCodeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpCodeParser();
    }

    // ========================================================================
    // Direct Collection Initialization Tests
    // ========================================================================

    public function test_detects_array_collection_initialization(): void
    {
        // Given: A method that initializes with ArrayCollection
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithArrayCollection');

        // When: We check if 'items' is initialized
        $result = $this->parser->hasCollectionInitialization($method, 'items');

        // Then: It should be detected
        self::assertTrue($result, 'Should detect ArrayCollection initialization');
    }

    public function test_detects_array_initialization(): void
    {
        // Given: A method that initializes with []
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithArray');

        // When: We check if 'items' is initialized
        $result = $this->parser->hasCollectionInitialization($method, 'items');

        // Then: It should be detected
        self::assertTrue($result, 'Should detect array [] initialization');
    }

    public function test_detects_fqn_array_collection(): void
    {
        // Given: A method with fully qualified class name
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithFQN');

        // When: We check if 'items' is initialized
        $result = $this->parser->hasCollectionInitialization($method, 'items');

        // Then: It should be detected
        self::assertTrue($result, 'Should detect FQN ArrayCollection initialization');
    }

    // ========================================================================
    // Method Call Tests
    // ========================================================================

    public function test_detects_initialization_method_call(): void
    {
        // Given: A method that calls initializeItemsCollection()
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithMethodCall');

        // When: We check for method call pattern
        $result = $this->parser->hasMethodCall($method, 'initializeItemsCollection');

        // Then: It should be detected
        self::assertTrue($result, 'Should detect initialization method call');
    }

    public function test_detects_wildcard_method_call(): void
    {
        // Given: A method that calls various init methods
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithMethodCall');

        // When: We check with wildcard pattern
        $result = $this->parser->hasMethodCall($method, 'initialize*Collection');

        // Then: It should match
        self::assertTrue($result, 'Should match wildcard pattern');
    }

    // ========================================================================
    // Negative Tests (Should NOT Detect)
    // ========================================================================

    public function test_ignores_commented_initialization(): void
    {
        // Given: A method with initialization only in comments
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithCommentedInit');

        // When: We check if 'items' is initialized
        $result = $this->parser->hasCollectionInitialization($method, 'items');

        // Then: It should NOT be detected
        self::assertFalse($result, 'Should ignore commented-out initialization');
    }

    public function test_ignores_string_literals(): void
    {
        // Given: A method with initialization in string
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithStringLiteral');

        // When: We check if 'items' is initialized
        $result = $this->parser->hasCollectionInitialization($method, 'items');

        // Then: It should NOT be detected
        self::assertFalse($result, 'Should ignore initialization in string literals');
    }

    public function test_ignores_other_fields(): void
    {
        // Given: A method that initializes 'otherField' not 'items'
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithOtherField');

        // When: We check if 'items' is initialized
        $result = $this->parser->hasCollectionInitialization($method, 'items');

        // Then: It should NOT be detected
        self::assertFalse($result, 'Should only detect the specific field');
    }

    public function test_returns_false_for_non_existent_field(): void
    {
        // Given: A valid method
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithArrayCollection');

        // When: We check for non-existent field
        $result = $this->parser->hasCollectionInitialization($method, 'nonExistentField');

        // Then: It should return false
        self::assertFalse($result, 'Should return false for non-existent field');
    }

    // ========================================================================
    // Edge Cases & Variations
    // ========================================================================

    public function test_handles_various_spacing(): void
    {
        // Given: A method with unusual spacing
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithWeirdSpacing');

        // When: We check if 'items' is initialized
        $result = $this->parser->hasCollectionInitialization($method, 'items');

        // Then: It should still be detected (PHP Parser handles this)
        self::assertTrue($result, 'Should handle various spacing');
    }

    public function test_handles_multiline_assignment(): void
    {
        // Given: A method with multiline assignment
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithMultiline');

        // When: We check if 'items' is initialized
        $result = $this->parser->hasCollectionInitialization($method, 'items');

        // Then: It should be detected
        self::assertTrue($result, 'Should handle multiline assignments');
    }

    // ========================================================================
    // Cache Tests
    // ========================================================================

    public function test_caches_ast(): void
    {
        // Given: A method
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithArrayCollection');

        // When: We parse it twice
        $this->parser->hasCollectionInitialization($method, 'items');
        $stats1 = $this->parser->getCacheStats();

        $this->parser->hasCollectionInitialization($method, 'items');
        $stats2 = $this->parser->getCacheStats();

        // Then: Cache should have one entry and not grow
        self::assertGreaterThan(0, $stats1['ast_entries']);
        self::assertSame($stats1['ast_entries'], $stats2['ast_entries'], 'Cache should reuse AST');
    }

    public function test_clear_cache(): void
    {
        // Given: Parser with cached AST
        $method = new ReflectionMethod(TestEntity::class, 'constructorWithArrayCollection');
        $this->parser->hasCollectionInitialization($method, 'items');

        self::assertGreaterThan(0, $this->parser->getCacheStats()['ast_entries']);

        // When: We clear the cache
        $this->parser->clearCache();

        // Then: Cache should be empty
        self::assertSame(0, $this->parser->getCacheStats()['ast_entries']);
    }
}

// ============================================================================
// Test Fixtures
// ============================================================================

class TestEntity
{
    /**
     * @var mixed
     * @phpstan-ignore-next-line Test fixture - property only written for testing AST detection
     */
    private $items;

    /**
     * @var mixed
     * @phpstan-ignore-next-line Test fixture - property only written for testing AST detection
     */
    private $otherField;

    public function constructorWithArrayCollection(): void
    {
        $this->items = new ArrayCollection();
    }

    public function constructorWithArray(): void
    {
        $this->items = [];
    }

    public function constructorWithFQN(): void
    {
        $this->items = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function constructorWithMethodCall(): void
    {
        $this->initializeItemsCollection();
    }

    public function constructorWithCommentedInit(): void
    {
        // $this->items = new ArrayCollection();
        /* $this->items = new ArrayCollection(); */
    }

    public function constructorWithStringLiteral(): void
    {
        $sql = '$this->items = new ArrayCollection()';
        echo $sql;
    }

    public function constructorWithOtherField(): void
    {
        $this->otherField = new ArrayCollection();
    }

    public function constructorWithWeirdSpacing(): void
    {
        $this->items   =   new ArrayCollection();
    }

    public function constructorWithMultiline(): void
    {
        $this->items = new ArrayCollection(
            // arguments
        );
    }

    private function initializeItemsCollection(): void
    {
        $this->items = new ArrayCollection();
    }
}
