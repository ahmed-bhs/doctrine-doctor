<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection;

use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\DependencyInjection\DoctrineDoctorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

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
        $method->setAccessible(true);

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
                'AhmedBhs\\DoctrineDoctor\\Analyzer\\Performance\\NPlusOneAnalyzer',
                'n_plus_one',
            ],
            'with_namespace_sql' => [
                'AhmedBhs\\DoctrineDoctor\\Analyzer\\Security\\SQLInjectionInRawQueriesAnalyzer',
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
        ];
    }

    #[Test]
    public function it_handles_class_without_analyzer_suffix(): void
    {
        $reflection = new ReflectionClass($this->extension);
        $method = $reflection->getMethod('classNameToConfigKey');
        $method->setAccessible(true);

        // If a class doesn't have "Analyzer" suffix, it should still work
        $result = $method->invoke($this->extension, 'NPlusOne');

        self::assertSame('n_plus_one', $result);
    }

    #[Test]
    public function it_handles_single_word_class_names(): void
    {
        $reflection = new ReflectionClass($this->extension);
        $method = $reflection->getMethod('classNameToConfigKey');
        $method->setAccessible(true);

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
}
