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
        self::assertTrue($this->renderer->exists($templateName), sprintf('Template %s does not exist', $templateName));

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
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function templateEdgeCasesProvider(): iterable
    {
        yield 'collection_initialization - no namespace' => [
            'Integrity/collection_initialization',
            [
                'entity_class'    => 'SimpleEntity',
                'field_name'      => 'items',
                'has_constructor' => false,
                'backtrace'       => [],
            ],
            'class without namespace',
        ];

        yield 'collection_initialization - with namespace' => [
            'Integrity/collection_initialization',
            [
                'entity_class'    => 'App\\Entity\\Product',
                'field_name'      => 'items',
                'has_constructor' => true,
                'backtrace'       => [],
            ],
            'class with namespace',
        ];

        yield 'sensitive_data_exposure - no namespace' => [
            'Security/sensitive_data_exposure',
            [
                'entity_class'    => 'User',
                'method_name'     => 'jsonSerialize',
                'exposed_fields'  => ['password', 'apiToken'],
                'exposure_type'   => 'serialization',
            ],
            'class without namespace',
        ];

        yield 'sensitive_data_exposure - with namespace' => [
            'Security/sensitive_data_exposure',
            [
                'entity_class'    => 'App\\Entity\\User',
                'method_name'     => 'jsonSerialize',
                'exposed_fields'  => ['password'],
                'exposure_type'   => 'serialization',
            ],
            'class with namespace',
        ];

        yield 'insecure_random - no namespace' => [
            'Security/insecure_random',
            [
                'entity_class'       => 'TokenGenerator',
                'method_name'        => 'generate',
                'insecure_function'  => 'rand',
            ],
            'class without namespace',
        ];

        yield 'insecure_random - with namespace' => [
            'Security/insecure_random',
            [
                'entity_class'       => 'App\\Security\\TokenGenerator',
                'method_name'        => 'generate',
                'insecure_function'  => 'mt_rand',
            ],
            'class with namespace',
        ];

        yield 'cascade_configuration - no namespace' => [
            'Integrity/cascade_configuration',
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
            'Integrity/cascade_configuration',
            [
                'entity_class'   => 'App\\Entity\\Order',
                'field_name'     => 'items',
                'issue_type'     => 'missing_cascade',
                'target_entity'  => 'App\\Entity\\OrderItem',
                'is_composition' => false,
            ],
            'classes with namespace',
        ];

        yield 'cascade_configuration - mixed namespaces' => [
            'Integrity/cascade_configuration',
            [
                'entity_class'   => 'App\\Entity\\Order',
                'field_name'     => 'items',
                'issue_type'     => 'incorrect_cascade',
                'target_entity'  => 'OrderItem',
                'is_composition' => true,
            ],
            'mixed namespaces',
        ];

        yield 'dto_hydration - missing optional aggregations' => [
            'Performance/dto_hydration',
            [
                'query_count' => 1,
            ],
            'missing aggregations array',
        ];

        yield 'query_caching_frequent - zero total time' => [
            'Performance/query_caching_frequent',
            [
                'sql'        => 'SELECT 1',
                'count'      => 1,
                'total_time' => 0.0,
                'avg_time'   => 0.0,
            ],
            'zero total time',
        ];

        yield 'group_by_aggregation - zero query count' => [
            'Performance/group_by_aggregation',
            [
                'entity'      => 'Article',
                'relation'    => 'comments',
                'query_count' => 0,
            ],
            'zero query count',
        ];

        yield 'configuration - partial context' => [
            'Configuration/configuration',
            [
                'setting' => 'doctrine.orm.auto_generate_proxy_classes',
            ],
            'partial context',
        ];
    }

    public function test_all_templates_have_valid_syntax(): void
    {
        $templates = glob($this->templateDirectory . '/{Integrity,Performance,Security,Configuration}/*.php', GLOB_BRACE);
        self::assertNotEmpty($templates, 'No templates found in directory');

        $invalidTemplates = [];

        foreach ($templates as $templatePath) {
            if (str_contains($templatePath, 'EXAMPLE')) {
                continue;
            }

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

    public function test_templates_do_not_use_unsafe_substr_pattern(): void
    {
        $templates = glob($this->templateDirectory . '/{Integrity,Performance,Security,Configuration}/*.php', GLOB_BRACE);

        $templatesWithIssue = [];

        if (false === $templates) {
            $templates = [];
        }

        foreach ($templates as $templatePath) {
            $content = file_get_contents($templatePath);
            if (false === $content) {
                continue;
            }

            if (1 === preg_match('/substr\s*\(\s*strrchr\s*\([^)]+\)[^)]*\)/', $content)) {
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

    public function test_all_templates_handle_empty_context_without_throwing(): void
    {
        $templates = glob($this->templateDirectory . '/{Integrity,Performance,Security,Configuration}/*.php', GLOB_BRACE);

        if (false === $templates) {
            $templates = [];
        }

        $failures = [];

        foreach ($templates as $templatePath) {
            if (str_contains($templatePath, 'EXAMPLE') || str_ends_with($templatePath, '/index.php')) {
                continue;
            }

            $templateName = str_replace($this->templateDirectory . '/', '', $templatePath);
            $templateName = substr($templateName, 0, -4);

            try {
                $result = $this->renderer->render($templateName, []);
                self::assertArrayHasKey('code', $result);
                self::assertArrayHasKey('description', $result);
            } catch (\Throwable $throwable) {
                $failures[] = sprintf('%s: %s', $templateName, $throwable->getMessage());
            }
        }

        self::assertEmpty($failures, "Templates failing with empty context:\n" . implode("\n", $failures));
    }

    /**
     * @dataProvider entityClassFormatsProvider
     */
    public function test_collection_initialization_with_various_formats(string $entityClass, string $expectedShortName): void
    {
        self::assertTrue($this->renderer->exists('Integrity/collection_initialization'));

        $result = $this->renderer->render('Integrity/collection_initialization', [
            'entity_class'    => $entityClass,
            'field_name'      => 'items',
            'has_constructor' => false,
            'backtrace'       => [],
        ]);

        self::assertStringContainsString($expectedShortName, $result['code']);
        self::assertStringContainsString($expectedShortName, $result['description']);
    }

    public function test_dto_hydration_handles_missing_aggregations(): void
    {
        $result = $this->renderer->render('Performance/dto_hydration', [
            'query_count' => 1,
        ]);

        self::assertStringContainsString('1 aggregation query', $result['code']);
        self::assertStringNotContainsString('aggregation query (', $result['code']);
    }

    public function test_query_caching_frequent_handles_zero_total_time(): void
    {
        $result = $this->renderer->render('Performance/query_caching_frequent', [
            'sql'        => 'SELECT 1',
            'count'      => 1,
            'total_time' => 0.0,
            'avg_time'   => 0.0,
        ]);

        self::assertStringContainsString('Result cache saves ~0%', $result['code']);
        self::assertStringContainsString('0.00ms total', $result['description']);
    }

    public function test_group_by_aggregation_handles_zero_query_count(): void
    {
        $result = $this->renderer->render('Performance/group_by_aggregation', [
            'entity'      => 'Article',
            'relation'    => 'comments',
            'query_count' => 0,
        ]);

        self::assertStringContainsString('Query reduction:</strong> 0%', $result['code']);
    }

    public function test_configuration_handles_partial_context(): void
    {
        $result = $this->renderer->render('Configuration/configuration', [
            'setting' => 'doctrine.orm.auto_generate_proxy_classes',
        ]);

        self::assertStringContainsString('Configuration Issue', $result['code']);
        self::assertStringContainsString('Change doctrine.orm.auto_generate_proxy_classes from "" to ""', $result['description']);
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
     * @dataProvider allTemplatesProvider
     */
    public function test_all_templates_can_render_with_valid_data(string $templateName, array $context): void
    {
        self::assertTrue($this->renderer->exists($templateName), sprintf('Template %s does not exist', $templateName));

        try {
            $result = $this->renderer->render($templateName, $context);

            self::assertIsArray($result, sprintf('Template %s must return array', $templateName));
            self::assertArrayHasKey('code', $result, sprintf('Template %s missing "code" key', $templateName));
            self::assertArrayHasKey('description', $result, sprintf('Template %s missing "description" key', $templateName));
            self::assertIsString($result['code'], sprintf('Template %s code must be string', $templateName));
            self::assertIsString($result['description'], sprintf('Template %s description must be string', $templateName));
            self::assertNotEmpty($result['code'], sprintf('Template %s returned empty code', $templateName));
            self::assertNotEmpty($result['description'], sprintf('Template %s returned empty description', $templateName));

            self::assertStringNotContainsString('Parse error', $result['code']);
            self::assertStringNotContainsString('Fatal error', $result['code']);
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

        $templates = [
            'Performance/aggregation_with_inner_join' => [
                'query'       => 'SELECT p FROM Product p INNER JOIN p.category c',
                'aggregation' => 'COUNT',
            ],
            'Performance/batch_operation' => [
                'table'           => 'products',
                'operation_count' => 1000,
            ],
            'Integrity/bidirectional_cascade_set_null' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
            ],
            'Integrity/bidirectional_inconsistency_generic' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'customer',
                'target_entity' => 'App\\Entity\\Customer',
                'mapped_by'     => 'orders',
            ],
            'Integrity/bidirectional_ondelete_no_orm' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'customer',
                'target_entity' => 'App\\Entity\\Customer',
                'on_delete'     => 'CASCADE',
            ],
            'Integrity/bidirectional_orphan_no_persist' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
            ],
            'Integrity/bidirectional_orphan_nullable' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
            ],
            'Integrity/blameable_non_nullable_created_by' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdBy',
            ],
            'Integrity/blameable_public_setter' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdBy',
            ],
            'Integrity/blameable_target_entity' => [
                'entity_class'   => 'App\\Entity\\Article',
                'field_name'     => 'author',
                'current_target' => 'App\\Entity\\Post',
            ],
            'Integrity/cascade_configuration' => [
                'entity_class'   => 'App\\Entity\\Order',
                'field_name'     => 'items',
                'issue_type'     => 'missing_cascade',
                'target_entity'  => 'App\\Entity\\OrderItem',
                'is_composition' => true,
            ],
            'Integrity/cascade_persist_independent' => [
                'entity_class'  => 'App\\Entity\\Product',
                'field_name'    => 'category',
                'target_entity' => 'App\\Entity\\Category',
            ],
            'Integrity/cascade_remove_many_to_many' => [
                'entity_class'  => 'App\\Entity\\Product',
                'field_name'    => 'tags',
                'target_entity' => 'App\\Entity\\Tag',
            ],
            'Integrity/cascade_remove_many_to_one' => [
                'entity_class'  => 'App\\Entity\\Product',
                'field_name'    => 'category',
                'target_entity' => 'App\\Entity\\Category',
            ],
            'Integrity/code_suggestion' => [
                'description' => 'Optimize query performance',
                'code'        => 'SELECT p FROM Product p',
                'file_path'   => 'src/Repository/ProductRepository.php',
            ],
            'Integrity/collection_initialization' => [
                'entity_class'    => 'App\\Entity\\Order',
                'field_name'      => 'items',
                'has_constructor' => false,
                'backtrace'       => [],
            ],
            'Configuration/configuration' => [
                'setting'           => 'max_execution_time',
                'current_value'     => '30',
                'recommended_value' => '60',
                'description'       => 'Increase execution time for long queries',
                'fix_command'       => 'php bin/console config:set max_execution_time 60',
            ],
            'Configuration/array_cache_production' => [
                'cache_type'     => 'metadata',
                'current_config' => 'array',
                'cache_label'    => 'Metadata cache',
            ],
            'Configuration/proxy_auto_generate' => [
                'current_value'     => 'true',
                'recommended_value' => 'false',
            ],
            'Performance/date_function_optimization' => [
                'query'         => 'SELECT p FROM Product p WHERE YEAR(p.createdAt) = 2024',
                'function_name' => 'YEAR',
                'field_name'    => 'createdAt',
            ],
            'Integrity/decimal_excessive_precision' => [
                'entity_class'      => 'App\\Entity\\Product',
                'field_name'        => 'price',
                'current_precision' => 20,
                'current_scale'     => 10,
            ],
            'Integrity/decimal_insufficient_precision' => [
                'entity_class'      => 'App\\Entity\\Product',
                'field_name'        => 'price',
                'current_precision' => 5,
                'current_scale'     => 2,
            ],
            'Integrity/decimal_missing_precision' => [
                'options'              => ['precision' => null, 'scale' => null],
                'understanding_points' => ['Point 1', 'Point 2'],
                'info_message'         => 'Missing precision configuration',
            ],
            'Integrity/decimal_unusual_scale' => [
                'entity_class'  => 'App\\Entity\\Product',
                'field_name'    => 'weight',
                'current_scale' => 8,
            ],
            'Integrity/division_by_zero' => [
                'unsafe_division' => 'revenue / quantity',
                'safe_division'   => 'revenue / NULLIF(quantity, 0)',
                'dividend'        => 'revenue',
                'divisor'         => 'quantity',
            ],
            'Security/dql_injection' => [
                'query'                 => 'SELECT u FROM User u WHERE u.name = \'$name\'',
                'vulnerable_parameters' => ['name'],
                'risk_level'            => 'warning',
            ],
            'Performance/eager_loading' => [
                'entity'           => 'App\\Entity\\Product',
                'relation'         => 'category',
                'query_count'      => 101,
                'trigger_location' => 'ProductController::index',
            ],
            'Integrity/embeddable_mutability' => [
                'embeddable_class'  => 'App\\ValueObject\\Money',
                'mutability_issues' => ['Has public setters', 'Not readonly'],
            ],
            'Integrity/embeddable_value_object_methods' => [
                'embeddable_class' => 'App\\ValueObject\\Money',
                'missing_methods'  => ['equals', 'toString'],
            ],
            'Integrity/empty_in_clause' => [
                'options' => ['allow_empty' => false],
            ],
            'Integrity/entity_manager_in_entity' => [
                'entity_class' => 'App\\Entity\\Product',
                'method_name'  => 'save',
            ],
            'Integrity/float_for_money' => [
                'entity_class' => 'App\\Entity\\Product',
                'field_name'   => 'price',
            ],
            'Integrity/float_in_money_embeddable' => [
                'embeddable_class' => 'App\\ValueObject\\Money',
                'field_name'       => 'amount',
            ],
            'Performance/flush_in_loop' => [
                'flush_count'              => 100,
                'operations_between_flush' => 1,
            ],
            'Performance/flush_in_loop_compact' => [
                'flush_count'              => 50,
                'operations_between_flush' => 1,
            ],
            'Integrity/foreign_key_primitive' => [
                'entity_class'     => 'App\\Entity\\Order',
                'field_name'       => 'customerId',
                'target_entity'    => 'App\\Entity\\Customer',
                'association_type' => 'ManyToOne',
            ],
            'Performance/get_reference' => [
                'entity'      => 'App\\Entity\\Product',
                'occurrences' => 5,
            ],
            'Performance/group_by_aggregation' => [
                'entity'      => 'App\\Entity\\Product',
                'relation'    => 'items',
                'query_count' => 10,
            ],
            'Integrity/incorrect_null_comparison' => [
                'entity_class' => 'App\\Entity\\Product',
                'field_name'   => 'status',
            ],
            'Performance/index' => [
                'table'          => 'products',
                'columns'        => ['status', 'created_at'],
                'migration_code' => 'CREATE INDEX idx_status_date ON products(status, created_at)',
            ],
            'Performance/ineffective_like' => [
                'pattern'        => '%search%',
                'like_type'      => 'contains search',
                'original_query' => 'SELECT * FROM products WHERE name LIKE \'%value%\'',
            ],
            'Security/insecure_random' => [
                'entity_class'      => 'App\\Security\\TokenGenerator',
                'method_name'       => 'generate',
                'insecure_function' => 'rand',
            ],
            'Performance/join_left_on_not_null' => [
                'table'  => 'orders',
                'alias'  => 'o',
                'entity' => 'App\\Entity\\Order',
            ],
            'Performance/join_too_many' => [
                'query'      => 'SELECT p FROM Product p JOIN p.category c JOIN p.tags t JOIN p.reviews r',
                'join_count' => 4,
            ],
            'Performance/join_unused' => [
                'type'  => 'LEFT',
                'table' => 'categories',
                'alias' => 'c',
            ],
            'Performance/left_join_with_not_null' => [
                'table'  => 'orders',
                'alias'  => 'o',
                'entity' => 'App\\Entity\\Order',
            ],
            'Integrity/missing_blameable_trait' => [
                'entity_class'     => 'App\\Entity\\Article',
                'timestamp_fields' => ['createdAt', 'updatedAt'],
            ],
            'Integrity/missing_embeddable_opportunity' => [
                'entity_class'    => 'App\\Entity\\User',
                'embeddable_name' => 'Address',
                'fields'          => ['street', 'city', 'zipCode', 'country'],
            ],
            'Performance/missing_index' => [
                'table'   => 'products',
                'columns' => ['status'],
            ],
            'Performance/missing_index_generic' => [
                'table'   => 'products',
                'columns' => ['status', 'created_at'],
            ],
            'Integrity/missing_orphan_removal' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
            ],
            'Performance/multi_step_hydration' => [
                'query'      => 'SELECT p FROM Product p',
                'step_count' => 3,
            ],
            'Integrity/naming_convention_column' => [
                'entity_class' => 'App\\Entity\\Product',
                'field_name'   => 'productName',
                'column_name'  => 'productName',
            ],
            'Integrity/naming_convention_fk' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'customer',
                'column_name'   => 'customer',
                'target_entity' => 'App\\Entity\\Customer',
            ],
            'Integrity/naming_convention_index' => [
                'entity_class' => 'App\\Entity\\Product',
                'index_name'   => 'idx_product_status',
                'columns'      => ['status'],
            ],
            'Integrity/naming_convention_table' => [
                'entity_class' => 'App\\Entity\\Product',
                'table_name'   => 'Product',
            ],
            'Integrity/null_comparison' => [
                'incorrect' => 'field = NULL',
                'correct'   => 'field IS NULL',
                'field'     => 'status',
                'operator'  => '=',
            ],
            'Integrity/on_delete_cascade_mismatch' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
                'on_delete'     => 'CASCADE',
            ],
            'Integrity/orphan_removal' => [
                'entity_class'  => 'App\\Entity\\Order',
                'field_name'    => 'items',
                'target_entity' => 'App\\Entity\\OrderItem',
            ],
            'Performance/order_by_without_limit' => [
                'query' => 'SELECT p FROM Product p ORDER BY p.createdAt DESC',
            ],
            'Performance/over_eager_loading' => [
                'entity'      => 'App\\Entity\\Product',
                'relation'    => 'reviews',
                'query_count' => 50,
            ],
            'Performance/pagination' => [
                'method'       => 'findAll',
                'result_count' => 10000,
            ],
            'Integrity/primary_key_auto_increment' => [
                'entity_name' => 'App\\Entity\\Product',
                'short_name'  => 'Product',
            ],
            'Integrity/primary_key_mixed' => [
                'auto_increment_count'    => 15,
                'uuid_count'              => 5,
                'auto_increment_entities' => ['App\\Entity\\Product', 'App\\Entity\\Order'],
                'uuid_entities'           => ['App\\Entity\\User'],
            ],
            'Integrity/primary_key_uuid_v7' => [
                'entity_name' => 'App\\Entity\\Product',
                'short_name'  => 'Product',
            ],
            'Performance/query_caching_frequent' => [
                'sql'        => 'SELECT * FROM products WHERE status = ?',
                'count'      => 50,
                'total_time' => 250.5,
                'avg_time'   => 5.01,
            ],
            'Performance/query_caching_static' => [
                'sql'        => 'SELECT * FROM countries',
                'table_name' => 'countries',
            ],
            'Performance/query_optimization' => [
                'code'           => 'SELECT p FROM Product p',
                'optimization'   => 'Add index on status column',
                'execution_time' => 125.5,
                'threshold'      => 100.0,
            ],
            'Security/sensitive_data_exposure' => [
                'entity_class'   => 'App\\Entity\\User',
                'method_name'    => 'jsonSerialize',
                'exposed_fields' => ['password', 'apiToken'],
                'exposure_type'  => 'serialization',
            ],
            'Performance/setMaxResults_with_collection_join' => [
                'entity_hint' => 'Product',
            ],
            'Integrity/soft_delete_cascade_conflict' => [
                'entity_class' => 'App\\Entity\\Order',
                'field_name'   => 'category',
            ],
            'Integrity/soft_delete_immutable' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'deletedAt',
            ],
            'Integrity/soft_delete_nullable' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'deletedAt',
            ],
            'Integrity/soft_delete_setter' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'deletedAt',
            ],
            'Integrity/soft_delete_timezone' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'deletedAt',
            ],
            'Security/sql_injection' => [
                'class_name'         => 'App\\Repository\\UserRepository',
                'method_name'        => 'findByName',
                'vulnerability_type' => 'concatenation',
            ],
            'Integrity/timestampable_immutable_datetime' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdAt',
            ],
            'Integrity/timestampable_non_nullable_created_at' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdAt',
            ],
            'Integrity/timestampable_public_setter' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'updatedAt',
            ],
            'Integrity/timestampable_timezone' => [
                'entity_class' => 'App\\Entity\\Article',
                'field_name'   => 'createdAt',
            ],
            'Integrity/timestampable_timezone_global' => [
                'total_fields' => 25,
            ],
            'Integrity/timestampable_timezone_inconsistency' => [
                'datetime_count'   => 10,
                'datetimetz_count' => 3,
            ],
            'Integrity/type_hint_decimal_float_mismatch' => [
                'entity_class' => 'App\\Entity\\Product',
                'field_name'   => 'price',
            ],
            'Integrity/type_hint_mismatch' => [
                'bad_code'           => 'public function setPrice($price)',
                'good_code'          => 'public function setPrice(float $price)',
                'description'        => 'Missing type hint',
                'performance_impact' => 'Low',
            ],
            'Performance/batch_fetch' => [
                'entity'      => 'App\\Entity\\Product',
                'relation'    => 'tags',
                'query_count' => 20,
            ],
            'Performance/collection_eager_loading' => [
                'entity'      => 'App\\Entity\\Product',
                'relation'    => 'reviews',
                'query_count' => 30,
            ],
            'Performance/denormalization' => [
                'entity'   => 'App\\Entity\\Product',
                'relation' => 'reviews',
            ],
            'Performance/dto_hydration' => [
                'query_count'  => 10,
                'aggregations' => ['COUNT', 'SUM'],
                'has_group_by' => true,
            ],
            'Performance/excessive_hydration' => [
                'query'        => 'SELECT p FROM Product p',
                'column_count' => 50,
            ],
            'Performance/extra_lazy' => [
                'entity'      => 'App\\Entity\\Product',
                'relation'    => 'reviews',
                'query_count' => 100,
            ],
            'Performance/nested_eager_loading' => [
                'entities'    => ['Product', 'Category', 'Parent'],
                'depth'       => 3,
                'query_count' => 50,
                'chain'       => 'Product -> Category -> Parent',
            ],
            'Performance/unused_eager_load' => [
                'unused_tables'  => ['reviews'],
                'unused_aliases' => ['r'],
                'count'          => 1,
            ],
        ];

        foreach ($templates as $templateName => $specificContext) {
            $context = array_merge($baseContext, $specificContext);
            yield $templateName => [$templateName, $context];
        }
    }

    /**
     * @dataProvider blameableFieldNameCollisionProvider
     */
    public function test_blameable_template_does_not_produce_duplicate_properties(string $fieldName): void
    {
        $result = $this->renderer->render('Integrity/blameable_target_entity', [
            'entity_class'   => 'App\\Entity\\Article',
            'field_name'     => $fieldName,
            'current_target' => 'App\\Entity\\Post',
        ]);

        $code = $result['code'];
        preg_match_all('/\$createdBy/', $code, $createdByMatches);
        preg_match_all('/\$updatedBy/', $code, $updatedByMatches);

        self::assertLessThanOrEqual(2, \count($createdByMatches[0]), "Template should not produce duplicate \$createdBy property declarations when field_name is '{$fieldName}'");
        self::assertLessThanOrEqual(2, \count($updatedByMatches[0]), "Template should not produce duplicate \$updatedBy property declarations when field_name is '{$fieldName}'");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blameableFieldNameCollisionProvider(): iterable
    {
        yield 'field named createdBy' => ['createdBy'];
        yield 'field named updatedBy' => ['updatedBy'];
    }

    /**
     * @dataProvider queryCachingFrequentProvider
     */
    public function test_query_caching_frequent_template(array $context, string $scenario): void
    {
        self::assertTrue($this->renderer->exists('Performance/query_caching_frequent'));

        $result = $this->renderer->render('Performance/query_caching_frequent', $context);

        self::assertStringContainsString((string) $context['count'], $result['code'], "Template should display count for {$scenario}");
        self::assertStringContainsString($context['sql'], $result['code'], "Template should display SQL for {$scenario}");
        self::assertStringContainsString((string) $context['count'], $result['description']);
    }

    public function test_template_renderer_keeps_apostrophes_readable_in_code_blocks(): void
    {
        $result = $this->renderer->render('Performance/query_caching_frequent', [
            'sql'        => "SELECT * FROM users WHERE last_name = 'O\\'Connor'",
            'count'      => 10,
            'total_time' => 120.0,
            'avg_time'   => 12.0,
        ]);

        self::assertStringContainsString("last_name = 'O\\'Connor'", $result['code']);
        self::assertStringNotContainsString('&apos;', $result['code']);
        self::assertStringNotContainsString('&#039;', $result['code']);
        self::assertStringNotContainsString('&#39;', $result['code']);
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
