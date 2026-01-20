<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Template\Renderer;

use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test to ensure all templates handle edge cases properly.
 *
 * This test validates that templates can handle:
 * - Classes without namespaces
 * - Empty strings
 * - Null values
 * - Special characters
 */
final class TemplateValidationTest extends TestCase
{
    private PhpTemplateRenderer $renderer;

    private string $templateDirectory;

    protected function setUp(): void
    {
        $this->templateDirectory = dirname(__DIR__, 4) . '/src/Template/Suggestions';
        $this->renderer          = new PhpTemplateRenderer(
            $this->templateDirectory,
            new NullLogger(),
        );
    }

    /**
     * @dataProvider templateEdgeCasesProvider
     */
    public function test_template_handles_edge_cases(string $templateName, array $context, string $testCase): void
    {
        // Skip if template doesn't exist
        if (!$this->renderer->exists($templateName)) {
            self::markTestSkipped(sprintf('Template %s does not exist', $templateName));
        }

        try {
            $result = $this->renderer->render($templateName, $context);

            self::assertIsArray($result);
            self::assertArrayHasKey('code', $result);
            self::assertArrayHasKey('description', $result);
            self::assertIsString($result['code']);
            self::assertIsString($result['description']);
            self::assertNotEmpty($result['code'], sprintf('Template %s returned empty code for %s', $templateName, $testCase));
            self::assertNotEmpty($result['description'], sprintf('Template %s returned empty description for %s', $templateName, $testCase));
        } catch (\Throwable $throwable) {
            self::fail(sprintf(
                "Template '%s' failed for test case '%s': %s\nContext: %s",
                $templateName,
                $testCase,
                $throwable->getMessage(),
                json_encode($context, JSON_PRETTY_PRINT) ?: '{}',
            ));
        }
    }

    /**
     * Provides edge case test data for templates.
     *
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function templateEdgeCasesProvider(): iterable
    {
        // Test collection_initialization with class without namespace
        yield 'collection_initialization - no namespace' => [
            'collection_initialization',
            [
                'entity_class'    => 'SimpleEntity',  // No namespace!
                'field_name'      => 'items',
                'has_constructor' => false,
                'backtrace'       => [],
            ],
            'class without namespace',
        ];

        yield 'collection_initialization - with namespace' => [
            'collection_initialization',
            [
                'entity_class'    => 'App\\Entity\\Product',
                'field_name'      => 'items',
                'has_constructor' => true,
                'backtrace'       => [],
            ],
            'class with namespace',
        ];

        // Test sensitive_data_exposure
        yield 'sensitive_data_exposure - no namespace' => [
            'sensitive_data_exposure',
            [
                'entity_class'    => 'User',
                'method_name'     => 'jsonSerialize',
                'exposed_fields'  => ['password', 'apiToken'],
                'exposure_type'   => 'serialization',
            ],
            'class without namespace',
        ];

        yield 'sensitive_data_exposure - with namespace' => [
            'sensitive_data_exposure',
            [
                'entity_class'    => 'App\\Entity\\User',
                'method_name'     => 'jsonSerialize',
                'exposed_fields'  => ['password'],
                'exposure_type'   => 'serialization',
            ],
            'class with namespace',
        ];

        // Test insecure_random
        yield 'insecure_random - no namespace' => [
            'insecure_random',
            [
                'entity_class'       => 'TokenGenerator',
                'method_name'        => 'generate',
                'insecure_function'  => 'rand',
            ],
            'class without namespace',
        ];

        yield 'insecure_random - with namespace' => [
            'insecure_random',
            [
                'entity_class'       => 'App\\Security\\TokenGenerator',
                'method_name'        => 'generate',
                'insecure_function'  => 'mt_rand',
            ],
            'class with namespace',
        ];

        // Test cascade_configuration
        yield 'cascade_configuration - no namespace' => [
            'cascade_configuration',
            [
                'entity_class'   => 'Order',
                'field_name'     => 'items',
                'issue_type'     => 'missing_cascade',
                'target_entity'  => 'OrderItem',
                'is_composition' => true,
            ],
            'classes without namespace',
        ];

        yield 'cascade_configuration - with namespace' => [
            'cascade_configuration',
            [
                'entity_class'   => 'App\\Entity\\Order',
                'field_name'     => 'items',
                'issue_type'     => 'missing_cascade',
                'target_entity'  => 'App\\Entity\\OrderItem',
                'is_composition' => false,
            ],
            'classes with namespace',
        ];

        // Test cascade_configuration with mixed namespaces
        yield 'cascade_configuration - mixed namespaces' => [
            'cascade_configuration',
            [
                'entity_class'   => 'App\\Entity\\Order',
                'field_name'     => 'items',
                'issue_type'     => 'incorrect_cascade',
                'target_entity'  => 'OrderItem',  // No namespace
                'is_composition' => true,
            ],
            'mixed namespaces',
        ];
    }

    /**
     * Test that all templates in the directory can be loaded without syntax errors.
     */
    public function test_all_templates_have_valid_syntax(): void
    {
        $templates = glob($this->templateDirectory . '/*.php');
        self::assertNotEmpty($templates, 'No templates found in directory');

        $invalidTemplates = [];

        foreach ($templates as $templatePath) {
            // Skip EXAMPLE templates
            if (str_contains($templatePath, 'EXAMPLE')) {
                continue;
            }

            // Check for syntax errors
            $output     = [];
            $returnCode = 0;
            exec('php -l ' . escapeshellarg($templatePath) . ' 2>&1', $output, $returnCode);

            if (0 !== $returnCode) {
                $invalidTemplates[] = basename($templatePath) . ': ' . implode("\n", $output);
            }
        }

        self::assertEmpty(
            $invalidTemplates,
            sprintf("Templates with syntax errors:\n%s", implode("\n", $invalidTemplates)),
        );
    }

    /**
     * Test that templates don't use unsafe substr(strrchr()) pattern.
     */
    public function test_templates_do_not_use_unsafe_substr_pattern(): void
    {
        $templates        = glob($this->templateDirectory . '/*.php');
        $templatesWithIssue = [];

        if (false === $templates) {
            $templates = [];
        }

        foreach ($templates as $templatePath) {
            $content = file_get_contents($templatePath);
            if (false === $content) {
                continue;
            }

            // Check for the unsafe pattern: substr(strrchr(...), 1)
            // This pattern is unsafe because strrchr can return false
            if (1 === preg_match('/substr\s*\(\s*strrchr\s*\([^)]+\)[^)]*\)/', $content)) {
                // Check if there's proper null handling
                if (1 !== preg_match('/strrchr\s*\([^)]+\)\s*!==\s*false/', $content) &&
                    1 !== preg_match('/false\s*!==\s*strrchr\s*\([^)]+\)/', $content)) {
                    $templatesWithIssue[] = basename($templatePath);
                }
            }
        }

        self::assertEmpty(
            $templatesWithIssue,
            sprintf(
                "Templates using unsafe substr(strrchr()) without null check:\n%s\n\n" .
                "Use this pattern instead:\n" .
                "\$lastBackslash = strrchr(\$className, '\\\\');\n" .
                "\$shortClass = \$lastBackslash !== false ? substr(\$lastBackslash, 1) : \$className;",
                implode("\n", $templatesWithIssue),
            ),
        );
    }

    /**
     * Test specific template with various entity class formats.
     *
     * @dataProvider entityClassFormatsProvider
     */
    public function test_collection_initialization_with_various_formats(string $entityClass, string $expectedShortName): void
    {
        if (!$this->renderer->exists('collection_initialization')) {
            self::markTestSkipped('collection_initialization template does not exist');
        }

        $result = $this->renderer->render('collection_initialization', [
            'entity_class'    => $entityClass,
            'field_name'      => 'items',
            'has_constructor' => false,
            'backtrace'       => [],
        ]);

        self::assertStringContainsString($expectedShortName, $result['code']);
        self::assertStringContainsString($expectedShortName, $result['description']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function entityClassFormatsProvider(): iterable
    {
        yield 'simple class name' => ['Product', 'Product'];
        yield 'one level namespace' => ['App\\Product', 'Product'];
        yield 'two level namespace' => ['App\\Entity\\Product', 'Product'];
        yield 'deep namespace' => ['App\\Domain\\Catalog\\Entity\\Product', 'Product'];
        yield 'class with underscore' => ['App\\Entity\\Product_Item', 'Product_Item'];
        yield 'class with number' => ['App\\Entity\\Product2', 'Product2'];
    }

    /**
     * Test ALL templates in the directory with generic valid data.
     * This ensures no template will fail due to missing data or edge cases.
     *
     * @dataProvider allTemplatesProvider
     */
    public function test_all_templates_can_render_with_valid_data(string $templateName, array $context): void
    {
        if (!$this->renderer->exists($templateName)) {
            self::markTestSkipped(sprintf('Template %s does not exist', $templateName));
        }

        try {
            $result = $this->renderer->render($templateName, $context);

            self::assertIsArray($result, sprintf('Template %s must return array', $templateName));
            self::assertArrayHasKey('code', $result, sprintf('Template %s missing "code" key', $templateName));
            self::assertArrayHasKey('description', $result, sprintf('Template %s missing "description" key', $templateName));
            self::assertIsString($result['code'], sprintf('Template %s code must be string', $templateName));
            self::assertIsString($result['description'], sprintf('Template %s description must be string', $templateName));
            self::assertNotEmpty($result['code'], sprintf('Template %s returned empty code', $templateName));
            self::assertNotEmpty($result['description'], sprintf('Template %s returned empty description', $templateName));

            // Additional validation: ensure no PHP errors in output
            self::assertStringNotContainsString('Parse error', $result['code']);
            self::assertStringNotContainsString('Fatal error', $result['code']);
            self::assertStringNotContainsString('Warning:', $result['code']);
            self::assertStringNotContainsString('Notice:', $result['code']);
        } catch (\Throwable $throwable) {
            self::fail(sprintf(
                "Template '%s' failed to render:\n" .
                "Error: %s\n" .
                "File: %s:%d\n" .
                "Context provided: %s",
                $templateName,
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine(),
                json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            ));
        }
    }

    /**
     * Provides test data for ALL templates in the suggestions directory.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function allTemplatesProvider(): iterable
    {
        $baseContext = [
            'entity_class'    => 'App\\Entity\\Product',
            'field_name'      => 'items',
            'table'           => 'products',
            'alias'           => 'p',
            'entity'          => 'App\\Entity\\Product',
            'method_name'     => 'findByStatus',
            'class_name'      => 'App\\Repository\\ProductRepository',
        ];

        // Generic test data for common patterns
        $templates = [
            'aggregation_with_inner_join' => [
                'query' => 'SELECT p FROM Product p INNER JOIN p.category c',
                'issue' => 'Using INNER JOIN with aggregation',
            ],
            'batch_operation' => [
                'table'           => 'products',
                'operation_count' => 1000,
            ],
            'bidirectional_cascade_set_null' => [
                'entity_class' => 'App\\Entity\\Order',
                'field_name'   => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
            ],
            'bidirectional_inconsistency_generic' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'customer',
                'target_entity' => 'App\\Entity\\Customer',
                'mapped_by'     => 'orders',
            ],
            'bidirectional_ondelete_no_orm' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'customer',
                'target_entity' => 'App\\Entity\\Customer',
                'on_delete'     => 'CASCADE',
            ],
            'bidirectional_orphan_no_persist' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
            ],
            'bidirectional_orphan_nullable' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
            ],
            'blameable_non_nullable_created_by' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdBy',
            ],
            'blameable_public_setter' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdBy',
            ],
            'blameable_target_entity' => [
                'entity_class'  => 'App\\Entity\\Article',
                'field_name'    => 'createdBy',
                'current_target' => 'App\\Entity\\User',
            ],
            'cascade_configuration' => [
                'entity_class'   => 'App\\Entity\\Order',
                'field_name'     => 'items',
                'issue_type'     => 'missing_cascade',
                'target_entity'  => 'App\\Entity\\OrderItem',
                'is_composition' => true,
            ],
            'cascade_persist_independent' => [
                'entity_class'  => 'App\\Entity\\Product',
                'field_name'    => 'category',
                'target_entity' => 'App\\Entity\\Category',
            ],
            'code_suggestion' => [
                'description' => 'Optimize query performance',
                'code'        => 'SELECT p FROM Product p',
                'file_path'   => 'src/Repository/ProductRepository.php',
            ],
            'collection_initialization' => [
                'entity_class'    => 'App\\Entity\\Order',
                'field_name'      => 'items',
                'has_constructor' => false,
                'backtrace'       => [],
            ],
            'configuration' => [
                'setting'           => 'max_execution_time',
                'current_value'     => '30',
                'recommended_value' => '60',
                'description'       => 'Increase execution time for long queries',
                'fix_command'       => 'php bin/console config:set max_execution_time 60',
            ],
            'date_function_optimization' => [
                'query'          => 'SELECT p FROM Product p WHERE YEAR(p.createdAt) = 2024',
                'function_name'  => 'YEAR',
                'field_name'     => 'createdAt',
            ],
            'decimal_excessive_precision' => [
                'entity_class'      => 'App\\Entity\\Product',
                'field_name'        => 'price',
                'current_precision' => 20,
                'current_scale'     => 10,
            ],
            'decimal_insufficient_precision' => [
                'entity_class'   => 'App\\Entity\\Product',
                'field_name'     => 'price',
                'current_precision' => 5,
                'current_scale'     => 2,
            ],
            'decimal_missing_precision' => [
                'options'              => ['precision' => null, 'scale' => null],
                'understanding_points' => ['Point 1', 'Point 2'],
                'info_message'         => 'Missing precision configuration',
            ],
            'dql_injection' => [
                'query'                  => 'SELECT u FROM User u WHERE u.name = ' . "'" . '$name' . "'",
                'vulnerable_parameters'  => ['name'],
                'risk_level'             => 'warning',
            ],
            'eager_loading' => [
                'entity'      => 'App\\Entity\\Product',
                'relation'    => 'category',
                'query_count' => 101,
            ],
            'embeddable_mutability' => [
                'embeddable_class'  => 'App\\ValueObject\\Money',
                'mutability_issues' => ['Has public setters', 'Not readonly'],
            ],
            'embeddable_value_object_methods' => [
                'embeddable_class' => 'App\\ValueObject\\Money',
                'missing_methods'  => ['equals', 'toString'],
            ],
            'empty_in_clause' => [
                'options' => ['allow_empty' => false],
            ],
            'float_for_money' => [
                'entity_class' => 'App\\Entity\\Product',
                'field_name'   => 'price',
            ],
            'float_in_money_embeddable' => [
                'embeddable_class' => 'App\\ValueObject\\Money',
                'field_name'       => 'amount',
            ],
            'flush_in_loop' => [
                'flush_count'              => 100,
                'operations_between_flush' => 1,
            ],
            'foreign_key_primitive' => [
                'entity_class'     => 'App\\Entity\\Order',
                'field_name'       => 'customerId',
                'target_entity'    => 'App\\Entity\\Customer',
                'association_type' => 'ManyToOne',
            ],
            'get_reference' => [
                'entity'      => 'App\\Entity\\Product',
                'occurrences' => 5,
            ],
            'incorrect_null_comparison' => [
                'bad_code'  => 'WHERE p.status = NULL',
                'good_code' => 'WHERE p.status IS NULL',
            ],
            'index' => [
                'table'          => 'products',
                'columns'        => ['status', 'created_at'],
                'migration_code' => 'CREATE INDEX idx_status_date ON products(status, created_at)',
            ],
            'insecure_random' => [
                'entity_class'      => 'App\\Security\\TokenGenerator',
                'method_name'       => 'generate',
                'insecure_function' => 'rand',
            ],
            'join_left_on_not_null' => [
                'table'  => 'orders',
                'alias'  => 'o',
                'entity' => 'App\\Entity\\Order',
            ],
            'join_unused' => [
                'type'  => 'LEFT',
                'table' => 'categories',
                'alias' => 'c',
            ],
            'missing_embeddable_opportunity' => [
                'entity_class'    => 'App\\Entity\\User',
                'embeddable_name' => 'Address',
                'fields'          => ['street', 'city', 'zipCode', 'country'],
            ],
            'pagination' => [
                'method'       => 'findAll',
                'result_count' => 10000,
            ],
            'primary_key_auto_increment' => [
                'entity_name'          => 'App\\Entity\\Product',
                'short_name'           => 'Product',
                'auto_increment_count' => 15,
                'uuid_count'           => 5,
                'total_count'          => 20,
            ],
            'primary_key_uuid_v7' => [
                'entity_name' => 'App\\Entity\\Product',
                'short_name'  => 'Product',
            ],
            'query_caching_frequent' => [
                'sql'        => 'SELECT * FROM products WHERE status = ?',
                'count'      => 50,
                'total_time' => 250.5,
                'avg_time'   => 5.01,
            ],
            'query_optimization' => [
                'code'           => 'SELECT p FROM Product p',
                'optimization'   => 'Add index on status column',
                'execution_time' => 125.5,
                'threshold'      => 100.0,
            ],
            'sensitive_data_exposure' => [
                'entity_class'   => 'App\\Entity\\User',
                'method_name'    => 'jsonSerialize',
                'exposed_fields' => ['password', 'apiToken'],
                'exposure_type'  => 'serialization',
            ],
            'setMaxResults_with_collection_join' => [
                'entity_hint' => 'Product',
            ],
            'sql_injection' => [
                'class_name'         => 'App\\Repository\\UserRepository',
                'method_name'        => 'findByName',
                'vulnerability_type' => 'concatenation',
            ],
            'timestampable_immutable_datetime' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdAt',
            ],
            'timestampable_non_nullable_created_at' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdAt',
            ],
            'timestampable_public_setter' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'updatedAt',
            ],
            'timestampable_timezone' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdAt',
            ],
            'timestampable_timezone_global' => [
                'total_fields' => 25,
            ],
            'type_hint_mismatch' => [
                'bad_code'           => 'public function setPrice($price)',
                'good_code'          => 'public function setPrice(float $price)',
                'description'        => 'Missing type hint',
                'performance_impact' => 'Low',
            ],
        ];

        foreach ($templates as $templateName => $specificContext) {
            $context = array_merge($baseContext, $specificContext);
            yield $templateName => [$templateName, $context];
        }
    }

    /**
     * Specific test for query_caching_frequent template with various scenarios.
     *
     * @dataProvider queryCachingFrequentProvider
     */
    public function test_query_caching_frequent_template(array $context, string $scenario): void
    {
        if (!$this->renderer->exists('query_caching_frequent')) {
            self::markTestSkipped('query_caching_frequent template does not exist');
        }

        $result = $this->renderer->render('query_caching_frequent', $context);

        self::assertStringContainsString((string) $context['count'], $result['code'], "Template should display count for {$scenario}");
        self::assertStringContainsString(number_format($context['total_time'], 2), $result['code'], "Template should display total_time for {$scenario}");
        self::assertStringContainsString(number_format($context['avg_time'], 2), $result['code'], "Template should display avg_time for {$scenario}");
        self::assertStringContainsString($context['sql'], $result['code'], "Template should display SQL for {$scenario}");

        // Verify description contains key metrics
        self::assertStringContainsString((string) $context['count'], $result['description']);
        self::assertStringContainsString(number_format($context['total_time'], 2), $result['description']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function queryCachingFrequentProvider(): iterable
    {
        yield 'low frequency query' => [
            [
                'sql'        => 'SELECT p FROM Product p WHERE p.id = ?',
                'count'      => 5,
                'total_time' => 25.5,
                'avg_time'   => 5.1,
            ],
            'low frequency',
        ];

        yield 'medium frequency query' => [
            [
                'sql'        => 'SELECT c FROM Category c ORDER BY c.name',
                'count'      => 50,
                'total_time' => 250.0,
                'avg_time'   => 5.0,
            ],
            'medium frequency',
        ];

        yield 'high frequency query' => [
            [
                'sql'        => 'SELECT u FROM User u WHERE u.email = ?',
                'count'      => 500,
                'total_time' => 2500.0,
                'avg_time'   => 5.0,
            ],
            'high frequency',
        ];

        yield 'slow query' => [
            [
                'sql'        => 'SELECT p FROM Product p JOIN p.category c JOIN p.reviews r',
                'count'      => 10,
                'total_time' => 1500.0,
                'avg_time'   => 150.0,
            ],
            'slow query',
        ];

        yield 'fast but frequent query' => [
            [
                'sql'        => 'SELECT COUNT(p) FROM Product p',
                'count'      => 1000,
                'total_time' => 1000.0,
                'avg_time'   => 1.0,
            ],
            'fast but frequent',
        ];
    }
}
