<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\DependencyInjection\Configuration;
use AhmedBhs\DoctrineDoctor\DependencyInjection\DoctrineDoctorExtension;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webmozart\Assert\Assert;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Finder\Finder;

/**
 * Unit tests for DoctrineDoctorExtension.
 * Tests the automatic analyzer discovery and naming convention conversion.
 */
final class DoctrineDoctorExtensionTest extends TestCase
{
    private DoctrineDoctorExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new DoctrineDoctorExtension();
    }

    #[Test]
    #[DataProvider('classNameToConfigKeyProvider')]
    public function it_converts_class_names_to_config_keys_correctly(
        string $className,
        string $expectedConfigKey,
    ): void {
        // Use reflection to access private method
        $reflection = new ReflectionClass($this->extension);
        $method = $reflection->getMethod('classNameToConfigKey');

        $result = $method->invoke($this->extension, $className);

        self::assertSame($expectedConfigKey, $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function classNameToConfigKeyProvider(): array
    {
        return [
            // Basic PascalCase conversions
            'simple_analyzer' => [
                'NPlusOneAnalyzer',
                'n_plus_one',
            ],
            'missing_index' => [
                'MissingIndexAnalyzer',
                'missing_index',
            ],
            'slow_query' => [
                'SlowQueryAnalyzer',
                'slow_query',
            ],

            // Acronyms (SQL, DTO, DQL) should stay together
            'sql_acronym' => [
                'SQLInjectionInRawQueriesAnalyzer',
                'sql_injection_in_raw_queries',
            ],
            'dql_acronym' => [
                'DQLInjectionAnalyzer',
                'dql_injection',
            ],
            'dto_acronym' => [
                'DTOHydrationAnalyzer',
                'dto_hydration',
            ],

            // Complex names
            'cascade_persist' => [
                'CascadePersistOnIndependentEntityAnalyzer',
                'cascade_persist_on_independent_entity',
            ],
            'entity_manager' => [
                'EntityManagerClearAnalyzer',
                'entity_manager_clear',
            ],
            'bidirectional' => [
                'BidirectionalConsistencyAnalyzer',
                'bidirectional_consistency',
            ],

            // Configuration analyzers
            'doctrine_cache' => [
                'DoctrineCacheAnalyzer',
                'doctrine_cache',
            ],
            'innodb_engine' => [
                'InnoDBEngineAnalyzer',
                'inno_db_engine', // InnoDB is treated as two words: Inno + DB
            ],
            'auto_generate_proxy' => [
                'AutoGenerateProxyClassesAnalyzer',
                'auto_generate_proxy_classes',
            ],

            // With full namespace (should extract short name)
            'with_namespace' => [
                \AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer::class,
                'n_plus_one',
            ],
            'with_namespace_sql' => [
                \AhmedBhs\DoctrineDoctor\Analyzer\Security\SQLInjectionInRawQueriesAnalyzer::class,
                'sql_injection_in_raw_queries',
            ],

            // Edge cases
            'join_optimization' => [
                'JoinOptimizationAnalyzer',
                'join_optimization',
            ],
            'collection_empty' => [
                'CollectionEmptyAccessAnalyzer',
                'collection_empty_access',
            ],
            'missing_embeddable' => [
                'MissingEmbeddableOpportunityAnalyzer',
                'missing_embeddable_opportunity',
            ],
            'query_caching_opportunity' => [
                'QueryCachingOpportunityAnalyzer',
                'query_caching_opportunity',
            ],
            'query_caching_opportunity_with_namespace' => [
                \AhmedBhs\DoctrineDoctor\Analyzer\Performance\QueryCachingOpportunityAnalyzer::class,
                'query_caching_opportunity',
            ],
        ];
    }

    #[Test]
    public function it_handles_class_without_analyzer_suffix(): void
    {
        $reflection = new ReflectionClass($this->extension);
        $method = $reflection->getMethod('classNameToConfigKey');

        // If a class doesn't have "Analyzer" suffix, it should still work
        $result = $method->invoke($this->extension, 'NPlusOne');

        self::assertSame('n_plus_one', $result);
    }

    #[Test]
    public function it_handles_single_word_class_names(): void
    {
        $reflection = new ReflectionClass($this->extension);
        $method = $reflection->getMethod('classNameToConfigKey');

        $result = $method->invoke($this->extension, 'CharsetAnalyzer');

        self::assertSame('charset', $result);
    }

    #[Test]
    public function it_does_not_load_services_when_disabled(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([['enabled' => false]], $container);

        self::assertFalse($container->hasParameter('doctrine_doctor.enabled'));
        self::assertFalse($container->hasDefinition(DoctrineDoctorDataCollector::class));
    }

    #[Test]
    public function it_loads_services_when_enabled(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([['enabled' => true]], $container);

        self::assertTrue($container->hasParameter('doctrine_doctor.enabled'));
        self::assertTrue($container->getParameter('doctrine_doctor.enabled'));
        self::assertTrue($container->hasDefinition(DoctrineDoctorDataCollector::class));
    }

    #[Test]
    public function it_loads_services_when_enabled_is_kernel_debug_placeholder(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $this->extension->load([['enabled' => '%kernel.debug%']], $container);

        self::assertTrue($container->hasParameter('doctrine_doctor.enabled'));
        self::assertTrue($container->getParameter('doctrine_doctor.enabled'));
        self::assertTrue($container->hasDefinition(DoctrineDoctorDataCollector::class));
    }

    #[Test]
    public function it_does_not_load_services_when_enabled_is_false_string(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([['enabled' => 'false']], $container);

        self::assertFalse($container->hasParameter('doctrine_doctor.enabled'));
        self::assertFalse($container->hasDefinition(DoctrineDoctorDataCollector::class));
    }

    #[Test]
    public function it_registers_suggestion_factory_interface_alias(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([['enabled' => true]], $container);

        self::assertTrue($container->hasAlias(SuggestionFactoryInterface::class));
        self::assertSame(SuggestionFactory::class, (string) $container->getAlias(SuggestionFactoryInterface::class));
    }

    #[Test]
    public function it_removes_a_single_disabled_analyzer(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([[
            'enabled' => true,
            'analyzers' => [
                'n_plus_one' => ['enabled' => false],
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer::class));
        self::assertTrue($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Performance\SlowQueryAnalyzer::class));
    }

    #[Test]
    public function it_registers_root_level_analyzers_when_declared_explicitly(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([['enabled' => true]], $container);

        self::assertTrue($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\NestedRelationshipN1Analyzer::class));
        self::assertTrue($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\UnusedEagerLoadAnalyzer::class));
    }

    #[Test]
    public function it_can_disable_explicitly_registered_root_level_analyzers(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([[
            'enabled' => true,
            'analyzers' => [
                'nested_relationship_n1' => ['enabled' => false],
                'unused_eager_load' => ['enabled' => false],
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\NestedRelationshipN1Analyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\UnusedEagerLoadAnalyzer::class));
    }

    #[Test]
    public function it_removes_analyzers_with_generated_config_keys(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([[
            'enabled' => true,
            'analyzers' => [
                'sql_injection_in_raw_queries' => ['enabled' => false],
                'cascade_persist_on_independent_entity' => ['enabled' => false],
                'missing_orphan_removal_on_composition' => ['enabled' => false],
                'cascade_remove_on_independent_entity' => ['enabled' => false],
                'orphan_removal_without_cascade_remove' => ['enabled' => false],
                'on_delete_cascade_mismatch' => ['enabled' => false],
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Security\SQLInjectionInRawQueriesAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadePersistOnIndependentEntityAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingOrphanRemovalOnCompositionAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeRemoveOnIndependentEntityAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OrphanRemovalWithoutCascadeRemoveAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OnDeleteCascadeMismatchAnalyzer::class));
    }

    #[Test]
    public function it_can_disable_query_caching_opportunity_analyzer(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([[
            'enabled' => true,
            'analyzers' => [
                'query_caching_opportunity' => ['enabled' => false],
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Performance\QueryCachingOpportunityAnalyzer::class));
    }

    #[Test]
    public function it_can_disable_query_caching_opportunity_analyzer_via_legacy_key(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([[
            'enabled' => true,
            'analyzers' => [
                'query_caching' => ['enabled' => false],
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Performance\QueryCachingOpportunityAnalyzer::class));
    }

    #[Test]
    public function it_removes_analyzers_with_legacy_config_keys(): void
    {
        $container = new ContainerBuilder();
        $this->extension->load([[
            'enabled' => true,
            'analyzers' => [
                'sql_injection_raw_queries' => ['enabled' => false],
                'cascade_persist_independent' => ['enabled' => false],
                'missing_orphan_removal' => ['enabled' => false],
                'cascade_remove_independent' => ['enabled' => false],
                'orphan_removal_no_cascade' => ['enabled' => false],
                'ondelete_mismatch' => ['enabled' => false],
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Security\SQLInjectionInRawQueriesAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadePersistOnIndependentEntityAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingOrphanRemovalOnCompositionAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeRemoveOnIndependentEntityAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OrphanRemovalWithoutCascadeRemoveAnalyzer::class));
        self::assertFalse($container->hasDefinition(\AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OnDeleteCascadeMismatchAnalyzer::class));
    }

    #[Test]
    public function it_does_not_register_twig_paths_when_disabled(): void
    {
        $container = $this->createContainerWithTwig(false);

        $this->extension->prepend($container);

        self::assertFalse($this->hasTwigDoctrineDoctorPath($container));
    }

    #[Test]
    public function it_registers_twig_paths_when_enabled(): void
    {
        $container = $this->createContainerWithTwig(true);

        $this->extension->prepend($container);

        self::assertTrue($this->hasTwigDoctrineDoctorPath($container));
    }

    #[Test]
    public function every_analyzer_has_a_matching_config_node(): void
    {
        $extension = new DoctrineDoctorExtension();
        $reflection = new ReflectionClass($extension);
        $method = $reflection->getMethod('classNameToConfigKey');

        $configuration = new Configuration();
        $tree = $configuration->getConfigTreeBuilder()->buildTree();
        Assert::isInstanceOf($tree, ArrayNode::class);
        $analyzersNode = $tree->getChildren()['analyzers'];
        Assert::isInstanceOf($analyzersNode, ArrayNode::class);
        $configKeys = array_keys($analyzersNode->getChildren());

        $analyzerDirs = [
            __DIR__ . '/../../../src/Analyzer/Performance',
            __DIR__ . '/../../../src/Analyzer/Security',
            __DIR__ . '/../../../src/Analyzer/Integrity',
            __DIR__ . '/../../../src/Analyzer/Configuration',
        ];

        $missingKeys = [];

        foreach ($analyzerDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $finder = Finder::create()->files()->name('*Analyzer*.php')->in($dir);

            foreach ($finder as $file) {
                $className = $file->getBasename('.php');

                $fullClass = $this->resolveFullClassName($dir, $className);
                if (null === $fullClass || !class_exists($fullClass)) {
                    continue;
                }

                $refClass = new ReflectionClass($fullClass);
                if ($refClass->isAbstract() || !$refClass->implementsInterface(AnalyzerInterface::class)) {
                    continue;
                }

                $configKey = $method->invoke($extension, $className);

                if (!in_array($configKey, $configKeys, true)) {
                    $missingKeys[] = $className . ' => ' . $configKey;
                }
            }
        }

        self::assertSame([], $missingKeys, sprintf(
            "The following analyzers have no matching config node in Configuration.php:\n%s",
            implode("\n", $missingKeys),
        ));
    }

    private function createContainerWithTwig(bool $enabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $twigExtension = new class() extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'twig';
            }
        };
        $container->registerExtension($twigExtension);
        $container->prependExtensionConfig('doctrine_doctor', ['enabled' => $enabled]);

        return $container;
    }

    private function hasTwigDoctrineDoctorPath(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig('twig') as $config) {
            foreach ($config['paths'] ?? [] as $namespace) {
                if ('doctrine_doctor' === $namespace) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolveFullClassName(string $dir, string $className): ?string
    {
        $namespaceMap = [
            'Performance' => 'AhmedBhs\\DoctrineDoctor\\Analyzer\\Performance\\',
            'Security' => 'AhmedBhs\\DoctrineDoctor\\Analyzer\\Security\\',
            'Integrity' => 'AhmedBhs\\DoctrineDoctor\\Analyzer\\Integrity\\',
            'Configuration' => 'AhmedBhs\\DoctrineDoctor\\Analyzer\\Configuration\\',
        ];

        foreach ($namespaceMap as $segment => $namespace) {
            if (str_contains($dir, DIRECTORY_SEPARATOR . $segment) || str_ends_with($dir, '/' . $segment)) {
                return $namespace . $className;
            }
        }

        return null;
    }
}
