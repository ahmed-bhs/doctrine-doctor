<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser\Visitor;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\CollectionInitializationVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CollectionInitializationVisitor.
 *
 * These tests validate the Visitor Pattern implementation for detecting
 * collection initializations in the PHP AST.
 */
final class CollectionInitializationVisitorTest extends TestCase
{
    private \PhpParser\Parser $parser;

    protected function setUp(): void
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    // ========================================================================
    // ArrayCollection Tests
    // ========================================================================

    public function test_detects_simple_array_collection_init(): void
    {
        // Given: Code with simple ArrayCollection initialization
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->items = new ArrayCollection();
            }
        }
        PHP;

        // When: We traverse with visitor
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Initialization should be detected
        self::assertTrue($visitor->hasInitialization());
    }

    public function test_detects_fqn_array_collection(): void
    {
        // Given: Code with fully qualified class name
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->items = new \Doctrine\Common\Collections\ArrayCollection();
            }
        }
        PHP;

        // When: We traverse with visitor
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Initialization should be detected
        self::assertTrue($visitor->hasInitialization());
    }

    // ========================================================================
    // Array Literal Tests
    // ========================================================================

    public function test_detects_empty_array_init(): void
    {
        // Given: Code with empty array initialization
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->items = [];
            }
        }
        PHP;

        // When: We traverse with visitor
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Initialization should be detected
        self::assertTrue($visitor->hasInitialization());
    }

    public function test_ignores_non_empty_array(): void
    {
        // Given: Code with non-empty array (not a collection init)
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->items = [1, 2, 3];
            }
        }
        PHP;

        // When: We traverse with visitor
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT be detected (not empty array)
        self::assertFalse($visitor->hasInitialization());
    }

    // ========================================================================
    // Field Specificity Tests
    // ========================================================================

    public function test_only_detects_specific_field(): void
    {
        // Given: Code initializing multiple fields
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->items = new ArrayCollection();
                $this->users = new ArrayCollection();
                $this->products = new ArrayCollection();
            }
        }
        PHP;

        // When: We look for specific field 'users'
        $visitor = new CollectionInitializationVisitor('users');
        $this->traverseCode($code, $visitor);

        // Then: Should detect only 'users'
        self::assertTrue($visitor->hasInitialization());
    }

    public function test_returns_false_for_different_field(): void
    {
        // Given: Code initializing 'items' but not 'users'
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->items = new ArrayCollection();
            }
        }
        PHP;

        // When: We look for 'users' (not initialized)
        $visitor = new CollectionInitializationVisitor('users');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect
        self::assertFalse($visitor->hasInitialization());
    }

    // ========================================================================
    // Negative Tests
    // ========================================================================

    public function test_ignores_comments_automatically(): void
    {
        // Given: Code with initialization only in comments
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                // $this->items = new ArrayCollection();
                /* $this->items = new ArrayCollection(); */
            }
        }
        PHP;

        // When: We traverse (PHP Parser ignores comments automatically)
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect
        self::assertFalse($visitor->hasInitialization());
    }

    public function test_ignores_string_literals(): void
    {
        // Given: Code with initialization in string
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $sql = '$this->items = new ArrayCollection()';
            }
        }
        PHP;

        // When: We traverse
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect (it's in a string)
        self::assertFalse($visitor->hasInitialization());
    }

    public function test_ignores_static_property_access(): void
    {
        // Given: Code with static property (not $this->)
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                self::$items = new ArrayCollection();
            }
        }
        PHP;

        // When: We traverse
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect (not $this->items)
        self::assertFalse($visitor->hasInitialization());
    }

    public function test_ignores_other_variables(): void
    {
        // Given: Code initializing local variable, not property
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $items = new ArrayCollection();
            }
        }
        PHP;

        // When: We traverse
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect (local variable, not $this->items)
        self::assertFalse($visitor->hasInitialization());
    }

    // ========================================================================
    // Complex Scenarios
    // ========================================================================

    public function test_handles_multiple_statements_correctly(): void
    {
        // Given: Code with multiple statements
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $temp = [];
                if (true) {
                    $this->items = new ArrayCollection();
                }
            }
        }
        PHP;

        // When: We traverse
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Should detect even inside if statement
        self::assertTrue($visitor->hasInitialization());
    }

    public function test_handles_nested_scopes(): void
    {
        // Given: Code with nested scopes
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                foreach ([] as $item) {
                    if (true) {
                        $this->items = new ArrayCollection();
                    }
                }
            }
        }
        PHP;

        // When: We traverse
        $visitor = new CollectionInitializationVisitor('items');
        $this->traverseCode($code, $visitor);

        // Then: Should detect in nested scope
        self::assertTrue($visitor->hasInitialization());
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function traverseCode(string $code, CollectionInitializationVisitor $visitor): void
    {
        $ast = $this->parser->parse($code);
        self::assertIsArray($ast, 'Parser should return an array');

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
