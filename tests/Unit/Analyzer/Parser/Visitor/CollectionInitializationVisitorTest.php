<?php

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

    public function testDetectsSimpleArrayCollectionInit(): void
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
        $this->assertTrue($visitor->hasInitialization());
    }

    public function testDetectsFQNArrayCollection(): void
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
        $this->assertTrue($visitor->hasInitialization());
    }

    // ========================================================================
    // Array Literal Tests
    // ========================================================================

    public function testDetectsEmptyArrayInit(): void
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
        $this->assertTrue($visitor->hasInitialization());
    }

    public function testIgnoresNonEmptyArray(): void
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
        $this->assertFalse($visitor->hasInitialization());
    }

    // ========================================================================
    // Field Specificity Tests
    // ========================================================================

    public function testOnlyDetectsSpecificField(): void
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
        $this->assertTrue($visitor->hasInitialization());
    }

    public function testReturnsFalseForDifferentField(): void
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
        $this->assertFalse($visitor->hasInitialization());
    }

    // ========================================================================
    // Negative Tests
    // ========================================================================

    public function testIgnoresCommentsAutomatically(): void
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
        $this->assertFalse($visitor->hasInitialization());
    }

    public function testIgnoresStringLiterals(): void
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
        $this->assertFalse($visitor->hasInitialization());
    }

    public function testIgnoresStaticPropertyAccess(): void
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
        $this->assertFalse($visitor->hasInitialization());
    }

    public function testIgnoresOtherVariables(): void
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
        $this->assertFalse($visitor->hasInitialization());
    }

    // ========================================================================
    // Complex Scenarios
    // ========================================================================

    public function testHandlesMultipleStatementsCorrectly(): void
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
        $this->assertTrue($visitor->hasInitialization());
    }

    public function testHandlesNestedScopes(): void
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
        $this->assertTrue($visitor->hasInitialization());
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function traverseCode(string $code, CollectionInitializationVisitor $visitor): void
    {
        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
