<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DependencyInjection;

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
        $configs = $this->resolveEnabledInConfigs(
            $container->getExtensionConfig($this->getAlias()),
            $container,
        );
        $config  = $this->processConfiguration(new Configuration(), $configs);

        if (!$config['enabled']) {
            return;
        }

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
        $configs       = $this->resolveEnabledInConfigs($configs, $container);
        $config        = $this->processConfiguration($configuration, $configs);

        if (!$config['enabled']) {
            return;
        }

        $this->registerGlobalParameters($container, $config);
        $this->registerAnalyzerParameters($container, $config);

        $yamlFileLoader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config'),
        );
        $yamlFileLoader->load('services.yaml');

        $this->disableAnalyzers($container, $config);

        if (isset($config['profiler']['show_in_toolbar']) && !$config['profiler']['show_in_toolbar']) {
            $container->getDefinition(DoctrineDoctorDataCollector::class)
                ->clearTag('data_collector');
        }
    }

    #[\Override]
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
        $containerBuilder->setParameter('doctrine_doctor.analysis.exclude_paths', $config['analysis']['exclude_paths']);

        $containerBuilder->setParameter('doctrine_doctor.debug.enabled', $config['debug']['enabled'] ?? false);
        $containerBuilder->setParameter('doctrine_doctor.debug.internal_logging', $config['debug']['internal_logging'] ?? false);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerAnalyzerParameters(ContainerBuilder $containerBuilder, array $config): void
    {
        $analyzers = $config['analyzers'];

        $containerBuilder->setParameter('doctrine_doctor.analyzers.n_plus_one.threshold', $analyzers['n_plus_one']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.slow_query.threshold', $analyzers['slow_query']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.hydration.row_threshold', $analyzers['hydration']['row_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.hydration.critical_threshold', $analyzers['hydration']['critical_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.eager_loading.join_threshold', $analyzers['eager_loading']['join_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.eager_loading.critical_join_threshold', $analyzers['eager_loading']['critical_join_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.lazy_loading.threshold', $analyzers['lazy_loading']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.bulk_operation.threshold', $analyzers['bulk_operation']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.partial_object.threshold', $analyzers['partial_object']['threshold']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.missing_index.slow_query_threshold', $analyzers['missing_index']['slow_query_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.missing_index.min_rows_scanned', $analyzers['missing_index']['min_rows_scanned']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.missing_index.explain_queries', $analyzers['missing_index']['explain_queries']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.find_all.threshold', $analyzers['find_all']['threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.get_reference.threshold', $analyzers['get_reference']['threshold']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.entity_manager_clear.batch_size_threshold', $analyzers['entity_manager_clear']['batch_size_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.flush_in_loop.flush_count_threshold', $analyzers['flush_in_loop']['flush_count_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.flush_in_loop.time_window_ms', $analyzers['flush_in_loop']['time_window_ms']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.join_optimization.max_joins_recommended', $analyzers['join_optimization']['max_joins_recommended']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.join_optimization.max_joins_critical', $analyzers['join_optimization']['max_joins_critical']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.cartesian_product.n1_collection_threshold', $analyzers['cartesian_product']['n1_collection_threshold']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.nested_relationship_n1.threshold', $analyzers['nested_relationship_n1']['threshold']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.query_caching_opportunity.static_tables', $analyzers['query_caching_opportunity']['static_tables']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.query_caching_opportunity.frequency_threshold', $analyzers['query_caching_opportunity']['frequency_threshold']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.sensitive_data_exposure.sensitive_patterns', $analyzers['sensitive_data_exposure']['sensitive_patterns']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.float_for_money.money_field_patterns', $analyzers['float_for_money']['money_field_patterns']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.float_for_money.money_entity_patterns', $analyzers['float_for_money']['money_entity_patterns']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.cascade_persist_on_independent_entity.independent_entity_patterns', $analyzers['cascade_persist_on_independent_entity']['independent_entity_patterns']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.transaction_boundary.max_flush_per_transaction', $analyzers['transaction_boundary']['max_flush_per_transaction']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.transaction_boundary.max_transaction_duration', $analyzers['transaction_boundary']['max_transaction_duration']);

        $containerBuilder->setParameter('doctrine_doctor.analyzers.single_table_inheritance_sparse_table.sparse_threshold', $analyzers['single_table_inheritance_sparse_table']['sparse_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.single_table_inheritance_sparse_table.min_unused_columns', $analyzers['single_table_inheritance_sparse_table']['min_unused_columns']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.class_table_inheritance_thin_subclass.min_fields_threshold', $analyzers['class_table_inheritance_thin_subclass']['min_fields_threshold']);
        $containerBuilder->setParameter('doctrine_doctor.analyzers.class_table_inheritance_depth.max_depth', $analyzers['class_table_inheritance_depth']['max_depth']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function disableAnalyzers(ContainerBuilder $containerBuilder, array $config): void
    {
        $allAnalyzers = $containerBuilder->findTaggedServiceIds('doctrine_doctor.analyzer');

        foreach (array_keys($allAnalyzers) as $analyzerClass) {
            $configKey = $this->classNameToConfigKey($analyzerClass);

            if (isset($config['analyzers'][$configKey])
                && false === (bool) ($config['analyzers'][$configKey]['enabled'] ?? true)
                && $containerBuilder->hasDefinition($analyzerClass)) {
                $containerBuilder->removeDefinition($analyzerClass);
            }
        }
    }

    /**
     * Converts an analyzer class name to its configuration key.
     * Uses naming convention: PascalCase → snake_case.
     *
     * Examples:
     *   NPlusOneAnalyzer → n_plus_one
     *   MissingIndexAnalyzer → missing_index
     *   SQLInjectionInRawQueriesAnalyzer → sql_injection_in_raw_queries
     *   DTOHydrationAnalyzer → dto_hydration
     */
    private function classNameToConfigKey(string $className): string
    {
        $lastBackslashPos = strrpos($className, '\\');
        $shortName = false !== $lastBackslashPos
            ? substr($className, $lastBackslashPos + 1)
            : $className;

        $withoutSuffix = (string) preg_replace('/Analyzer$/', '', $shortName);
        Assert::stringNotEmpty($withoutSuffix, 'Class name must not be empty after removing Analyzer suffix');

        $step1 = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $withoutSuffix);

        $step2 = (string) preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $step1);

        return strtolower($step2);
    }

    /**
     * Resolve and normalize only the root "enabled" key before strict boolean validation.
     *
     * @param array<array<string, mixed>> $configs
     *
     * @return array<array<string, mixed>>
     */
    private function resolveEnabledInConfigs(array $configs, ContainerBuilder $container): array
    {
        foreach ($configs as $index => $config) {
            if (!\is_array($config) || !\array_key_exists('enabled', $config)) {
                continue;
            }

            $resolved = $container->getParameterBag()->resolveValue($config['enabled']);

            if (\is_string($resolved)) {
                $parsed = filter_var(trim($resolved), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
                if (null !== $parsed) {
                    $resolved = $parsed;
                }
            }

            $configs[$index]['enabled'] = $resolved;
        }

        return $configs;
    }
}
