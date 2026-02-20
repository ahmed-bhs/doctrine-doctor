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
}
