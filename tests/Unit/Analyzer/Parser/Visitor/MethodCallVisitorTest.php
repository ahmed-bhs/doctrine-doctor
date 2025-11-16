<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser\Visitor;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\MethodCallVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MethodCallVisitor.
 *
 * These tests validate the Visitor Pattern implementation for detecting
 * method call patterns in the PHP AST, including wildcard support.
 */
final class MethodCallVisitorTest extends TestCase
{
    private \PhpParser\Parser $parser;

    protected function setUp(): void
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    // ========================================================================
    // Exact Method Name Tests
    // ========================================================================

    public function testDetectsExactMethodName(): void
    {
        // Given: Code with exact method call
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initializeItemsCollection();
            }
        }
        PHP;

        // When: We look for exact method name
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should detect the method call
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testReturnsFalseForDifferentMethodName(): void
    {
        // Given: Code calling one method
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initializeItemsCollection();
            }
        }
        PHP;

        // When: We look for different method name
        $visitor = new MethodCallVisitor('initializeUsersCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect
        $this->assertFalse($visitor->hasMethodCall());
    }

    // ========================================================================
    // Wildcard Pattern Tests
    // ========================================================================

    public function testDetectsWildcardPrefixPattern(): void
    {
        // Given: Code with method call
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initializeTranslationsCollection();
            }
        }
        PHP;

        // When: We use wildcard pattern
        $visitor = new MethodCallVisitor('initialize*Collection');
        $this->traverseCode($code, $visitor);

        // Then: Should match the pattern
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testDetectsWildcardSuffixPattern(): void
    {
        // Given: Code with method call
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initializeAll();
            }
        }
        PHP;

        // When: We use suffix wildcard
        $visitor = new MethodCallVisitor('initialize*');
        $this->traverseCode($code, $visitor);

        // Then: Should match
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testDetectsWildcardMiddlePattern(): void
    {
        // Given: Code with method call
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initializeItemsCollection();
            }
        }
        PHP;

        // When: We use middle wildcard
        $visitor = new MethodCallVisitor('initialize*Collection');
        $this->traverseCode($code, $visitor);

        // Then: Should match
        $this->assertTrue($visitor->hasMethodCall());
    }

    /**
     * @dataProvider wildcardPatternsProvider
     */
    public function testVariousWildcardPatterns(string $pattern, string $methodCall, bool $shouldMatch): void
    {
        // Given: Code with specific method call
        $code = <<<PHP
        <?php
        class Test {
            public function __construct() {
                \$this->{$methodCall}();
            }
        }
        PHP;

        // When: We check with pattern
        $visitor = new MethodCallVisitor($pattern);
        $this->traverseCode($code, $visitor);

        // Then: Should match or not based on expectation
        $this->assertSame($shouldMatch, $visitor->hasMethodCall(),
            "Pattern '{$pattern}' should " . ($shouldMatch ? '' : 'NOT ') . "match '{$methodCall}'");
    }

    public static function wildcardPatternsProvider(): array
    {
        return [
            // [pattern, methodCall, shouldMatch]
            'exact match' => ['initializeItems', 'initializeItems', true],
            'prefix wildcard matches' => ['initialize*', 'initializeItemsCollection', true],
            'suffix wildcard matches' => ['*Collection', 'initializeItemsCollection', true],
            'middle wildcard matches' => ['init*Collection', 'initializeItemsCollection', true],
            'prefix wildcard no match' => ['setup*', 'initializeItems', false],
            'suffix wildcard no match' => ['*Setup', 'initializeItems', false],
            'multiple wildcards' => ['init*Items*', 'initializeItemsCollection', true],
        ];
    }

    // ========================================================================
    // Negative Tests (Should NOT Detect)
    // ========================================================================

    public function testIgnoresOtherMethods(): void
    {
        // Given: Code calling unrelated method
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->doSomethingElse();
            }
        }
        PHP;

        // When: We look for initialization method
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect
        $this->assertFalse($visitor->hasMethodCall());
    }

    public function testIgnoresStaticMethodCalls(): void
    {
        // Given: Code with static method call
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                self::initializeItemsCollection();
            }
        }
        PHP;

        // When: We look for method (should only match $this->)
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect (not $this->)
        $this->assertFalse($visitor->hasMethodCall());
    }

    public function testIgnoresFunctionCalls(): void
    {
        // Given: Code with function call (not method)
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                initializeItemsCollection();
            }
        }
        PHP;

        // When: We look for method call
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect (function, not method)
        $this->assertFalse($visitor->hasMethodCall());
    }

    public function testIgnoresOtherObjectMethodCalls(): void
    {
        // Given: Code calling method on different object
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $obj->initializeItemsCollection();
            }
        }
        PHP;

        // When: We look for method (should only match $this->)
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect (not $this)
        $this->assertFalse($visitor->hasMethodCall());
    }

    public function testIgnoresCommentedMethodCall(): void
    {
        // Given: Code with method call in comments
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                // $this->initializeItemsCollection();
                /* $this->initializeItemsCollection(); */
            }
        }
        PHP;

        // When: We look for method call
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect (PHP Parser ignores comments)
        $this->assertFalse($visitor->hasMethodCall());
    }

    public function testIgnoresStringLiterals(): void
    {
        // Given: Code with method call in string
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $code = '$this->initializeItemsCollection()';
            }
        }
        PHP;

        // When: We look for method call
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT detect (it's in a string)
        $this->assertFalse($visitor->hasMethodCall());
    }

    // ========================================================================
    // Complex Scenarios
    // ========================================================================

    public function testDetectsMethodCallInNestedScope(): void
    {
        // Given: Code with method call in nested scope
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                if (true) {
                    $this->initializeItemsCollection();
                }
            }
        }
        PHP;

        // When: We look for method call
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should detect even in nested scope
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testDetectsMethodCallAmongMultipleCalls(): void
    {
        // Given: Code with multiple method calls
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->setUp();
                $this->initializeItemsCollection();
                $this->validate();
            }
        }
        PHP;

        // When: We look for specific method
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should detect the correct one
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testWildcardMatchesMultipleMethods(): void
    {
        // Given: Code with multiple initialization methods
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initializeItemsCollection();
                $this->initializeUsersCollection();
                $this->initializeProductsCollection();
            }
        }
        PHP;

        // When: We use wildcard pattern
        $visitor = new MethodCallVisitor('initialize*Collection');
        $this->traverseCode($code, $visitor);

        // Then: Should detect at least one match
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testHandlesDeeplyNestedScopes(): void
    {
        // Given: Code with deeply nested method call
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                foreach ($items as $item) {
                    if ($item->isValid()) {
                        try {
                            $this->initializeItemsCollection();
                        } catch (\Exception $e) {
                            // handle
                        }
                    }
                }
            }
        }
        PHP;

        // When: We look for method call
        $visitor = new MethodCallVisitor('initializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should detect in deeply nested scope
        $this->assertTrue($visitor->hasMethodCall());
    }

    // ========================================================================
    // Sylius-Style Pattern Tests
    // ========================================================================

    public function testDetectsSyliusStyleConstructorAliasing(): void
    {
        // Given: Sylius-style code with constructor aliasing
        $code = <<<'PHP'
        <?php
        class Product {
            use TranslatableTrait {
                __construct as private initializeTranslationsCollection;
            }

            public function __construct() {
                $this->initializeTranslationsCollection();
            }
        }
        PHP;

        // When: We look for the aliased method call
        $visitor = new MethodCallVisitor('initializeTranslationsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should detect the call
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testDetectsSyliusPatternWithWildcard(): void
    {
        // Given: Sylius-style code
        $code = <<<'PHP'
        <?php
        class Product {
            public function __construct() {
                $this->initializeTranslationsCollection();
            }
        }
        PHP;

        // When: We use wildcard pattern
        $visitor = new MethodCallVisitor('initialize*Collection');
        $this->traverseCode($code, $visitor);

        // Then: Should match
        $this->assertTrue($visitor->hasMethodCall());
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function testHandlesMethodNameWithNumbers(): void
    {
        // Given: Method name with numbers
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initialize2024Collection();
            }
        }
        PHP;

        // When: We use wildcard
        $visitor = new MethodCallVisitor('initialize*Collection');
        $this->traverseCode($code, $visitor);

        // Then: Should match
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testHandlesMethodNameWithUnderscores(): void
    {
        // Given: Method name with underscores
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initialize_items_collection();
            }
        }
        PHP;

        // When: We look for exact name
        $visitor = new MethodCallVisitor('initialize_items_collection');
        $this->traverseCode($code, $visitor);

        // Then: Should match
        $this->assertTrue($visitor->hasMethodCall());
    }

    public function testHandlesCaseSensitivity(): void
    {
        // Given: Method with specific case
        $code = <<<'PHP'
        <?php
        class Test {
            public function __construct() {
                $this->initializeItemsCollection();
            }
        }
        PHP;

        // When: We look for different case
        $visitor = new MethodCallVisitor('InitializeItemsCollection');
        $this->traverseCode($code, $visitor);

        // Then: Should NOT match (case-sensitive)
        $this->assertFalse($visitor->hasMethodCall());
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function traverseCode(string $code, MethodCallVisitor $visitor): void
    {
        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
