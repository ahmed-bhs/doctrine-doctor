<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DependencyInjection;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\AutoGenerateProxyClassesAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\CharsetAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\ConnectionPoolingAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\InnoDBEngineAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\StrictModeAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\BidirectionalConsistencyAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\BlameableTraitAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeAllAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeConfigurationAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadePersistOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeRemoveOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CollectionEmptyAccessAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CollectionInitializationAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\EntityManagerInEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\EntityStateConsistencyAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FinalEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingEmbeddableOpportunityAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingOrphanRemovalOnCompositionAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OnDeleteCascadeMismatchAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OrphanRemovalWithoutCascadeRemoveAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\TransactionBoundaryAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\BulkOperationAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\EagerLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\EntityManagerClearAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FindAllAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FlushInLoopAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\GetReferenceAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\HydrationAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\JoinOptimizationAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\LazyLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\SlowQueryAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Security\DQLInjectionAnalyzer;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Webmozart\Assert\Assert;

class DoctrineDoctorExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        // Register the Twig namespace for templates only if Twig is available
        // This makes Twig optional - the bundle works without it but profiler won't show
        if ($container->hasExtension('twig')) {
            $container->prependExtensionConfig('twig', [
                'paths' => [
                    '%kernel.project_dir%/vendor/ahmed-bhs/doctrine-doctor/templates' => 'doctrine_doctor',
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        // Set parameters from configuration BEFORE loading services
        $this->registerGlobalParameters($container, $config);
        $this->registerAnalyzerParameters($container, $config);

        $yamlFileLoader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config'),
        );
        $yamlFileLoader->load('services.yaml');

        if (!$config['enabled']) {
            $this->disableAllAnalyzers($container);

            return;
        }

        // Disable analyzers if not enabled in configuration
        $this->disableAnalyzers($container, $config);

        // Configure profiler visibility (assuming 'profiler' node exists in config)
        // This part was not in the provided snippet, but was in the previous version.
        // Re-adding it for completeness if the config structure supports it.
        // If the 'profiler' node is removed from Configuration.php, this will cause an error.
        if (isset($config['profiler']['show_in_toolbar']) && !$config['profiler']['show_in_toolbar']) {
            $container->getDefinition(DoctrineDoctorDataCollector::class)
                ->clearTag('data_collector');
        }
    }

    public function getAlias(): string
    {
        return 'doctrine_doctor';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerGlobalParameters(ContainerBuilder $containerBuilder, array $config): void
    {
        $containerBuilder->setParameter('doctrine_doctor.enabled', $config['enabled']);
        $containerBuilder->setParameter('doctrine_doctor.profiler.show_debug_info', $config['profiler']['show_debug_info']);
        $containerBuilder->setParameter('doctrine_doctor.analysis.exclude_third_party_entities', $config['analysis']['exclude_third_party_entities']);

        // Debug parameters (defaults to false for performance)
        $containerBuilder->setParameter('doctrine_doctor.debug.enabled', $config['debug']['enabled'] ?? false);
        $containerBuilder->setParameter('doctrine_doctor.debug.internal_logging', $config['debug']['internal_logging'] ?? false);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerAnalyzerParameters(ContainerBuilder $containerBuilder, array $config): void
    {
        $analyzers = $config['analyzers'];

        // Performance analyzers
        $containerBuilder->setParameter('doctrine_doctor.analyzers.n_plus_one.threshold', $analyzers['n_plus_one']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.slow_query.threshold', $analyzers['slow_query']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.hydration.row_threshold', $analyzers['hydration']['row_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.hydration.critical_threshold', $analyzers['hydration']['critical_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.eager_loading.join_threshold', $analyzers['eager_loading']['join_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.eager_loading.critical_join_threshold', $analyzers['eager_loading']['critical_join_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.lazy_loading.threshold', $analyzers['lazy_loading']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.bulk_operation.threshold', $analyzers['bulk_operation']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.partial_object.threshold', $analyzers['partial_object']['threshold']);

        // Optimization analyzers
        $containerBuilder->setParameter('doctrine_doctor.analyzers.missing_index.slow_query_threshold', $analyzers['missing_index']['slow_query_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.missing_index.min_rows_scanned', $analyzers['missing_index']['min_rows_scanned']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.missing_index.explain_queries', $analyzers['missing_index']['explain_queries']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.find_all.threshold', $analyzers['find_all']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.get_reference.threshold', $analyzers['get_reference']['threshold']);

        // Entity Manager analyzers
        $containerBuilder->setParameter('doctrine_doctor.analyzers.entity_manager_clear.batch_size_threshold', $analyzers['entity_manager_clear']['batch_size_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.flush_in_loop.flush_count_threshold', $analyzers['flush_in_loop']['flush_count_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.flush_in_loop.time_window_ms', $analyzers['flush_in_loop']['time_window_ms']);

        // Join optimization analyzers
        $containerBuilder->setParameter('doctrine_doctor.analyzers.join_optimization.max_joins_recommended', $analyzers['join_optimization']['max_joins_recommended']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.join_optimization.max_joins_critical', $analyzers['join_optimization']['max_joins_critical']);
    }

    private function disableAllAnalyzers(ContainerBuilder $containerBuilder): void
    {
        $containerBuilder->getDefinition(DoctrineDoctorDataCollector::class)->clearTag('data_collector');

        foreach (array_keys($containerBuilder->findTaggedServiceIds('doctrine_doctor.analyzer')) as $id) {
            $containerBuilder->removeDefinition($id);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function disableAnalyzers(ContainerBuilder $containerBuilder, array $config): void
    {
        $analyzerMap = [
            'n_plus_one' => NPlusOneAnalyzer::class,
            'missing_index' => MissingIndexAnalyzer::class,
            'slow_query' => SlowQueryAnalyzer::class,
            'hydration' => HydrationAnalyzer::class,
            'eager_loading' => EagerLoadingAnalyzer::class,
            'entity_manager_clear' => EntityManagerClearAnalyzer::class,
            'find_all' => FindAllAnalyzer::class,
            'get_reference' => GetReferenceAnalyzer::class,
            'flush_in_loop' => FlushInLoopAnalyzer::class,
            'lazy_loading' => LazyLoadingAnalyzer::class,
            'dql_injection' => DQLInjectionAnalyzer::class,
            'bulk_operation' => BulkOperationAnalyzer::class,
            'strict_mode' => StrictModeAnalyzer::class,
            'charset' => CharsetAnalyzer::class,
            'innodb_engine' => InnoDBEngineAnalyzer::class,
            'connection_pooling' => ConnectionPoolingAnalyzer::class,
            'collection_initialization' => CollectionInitializationAnalyzer::class,
            'cascade_configuration' => CascadeConfigurationAnalyzer::class,
            'cascade_all' => CascadeAllAnalyzer::class,
            'cascade_persist_on_independent_entity' => CascadePersistOnIndependentEntityAnalyzer::class,
            'cascade_remove_on_independent_entity' => CascadeRemoveOnIndependentEntityAnalyzer::class,
            'bidirectional_consistency' => BidirectionalConsistencyAnalyzer::class,
            'missing_orphan_removal_on_composition' => MissingOrphanRemovalOnCompositionAnalyzer::class,
            'orphan_removal_without_cascade_remove' => OrphanRemovalWithoutCascadeRemoveAnalyzer::class,
            'on_delete_cascade_mismatch' => OnDeleteCascadeMismatchAnalyzer::class,
            'entity_state_consistency' => EntityStateConsistencyAnalyzer::class,
            'entity_manager_in_entity' => EntityManagerInEntityAnalyzer::class,
            'final_entity' => FinalEntityAnalyzer::class,
            'transaction_boundary' => TransactionBoundaryAnalyzer::class,
            'auto_generate_proxy_classes' => AutoGenerateProxyClassesAnalyzer::class,
            'join_optimization' => JoinOptimizationAnalyzer::class,
            'collection_empty_access' => CollectionEmptyAccessAnalyzer::class,
            'missing_embeddable_opportunity' => MissingEmbeddableOpportunityAnalyzer::class,
            'blameable_trait' => BlameableTraitAnalyzer::class,
        ];

        Assert::isIterable($analyzerMap, '$analyzerMap must be iterable');

        foreach ($analyzerMap as $configKey => $analyzerClass) {
            if (isset($config['analyzers'][$configKey])
                && false === (bool) ($config['analyzers'][$configKey]['enabled'] ?? true)
                && $containerBuilder->hasDefinition($analyzerClass)) {
                $containerBuilder->removeDefinition($analyzerClass);
            }
        }
    }
}
