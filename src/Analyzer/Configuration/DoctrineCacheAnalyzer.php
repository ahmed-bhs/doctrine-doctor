<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Configuration;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\ConfigurationIssue;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\CacheFactory;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Detects suboptimal Doctrine cache configuration.
 * Using ArrayCache in production is CATASTROPHIC for performance:
 * - Metadata cache: Reparses entity annotations on EVERY request (-50-80% perf)
 * - Query cache: Recompiles DQL queries on EVERY execution (-30-50% perf)
 * - Result cache: No caching of query results at all
 * This is one of the most common production mistakes and has
 * massive performance impact.
 * Example:
 * BAD (default):
 *   metadata_cache_driver: array  # Catastrophic in production!
 *  GOOD:
 *   metadata_cache_driver:
 *     type: pool
 *     pool: doctrine.system_cache_pool  # Redis/APCu
 */
class DoctrineCacheAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Cache implementation class names.
     */
    private const ARRAY_CACHE_CLASSES = [
        'Doctrine\Common\Cache\ArrayCache',
        'Doctrine\Common\Cache\Cache',
        'Symfony\Component\Cache\Adapter\ArrayAdapter',
    ];

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private string $environment = 'prod',
        /**
         * @readonly
         */
        private ?string $projectDir = null,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                // First, check YAML configuration files for production issues
                // This allows detection even in dev/test environments
                $yamlIssues = $this->checkYamlConfiguration();
                foreach ($yamlIssues as $issue) {
                    yield $issue;
                }

                // Only analyze runtime configuration in production environment
                if ('prod' !== $this->environment) {
                    return;
                }

                $configuration = $this->entityManager->getConfiguration();
                // Check 1: Metadata Cache (CRITICAL)
                $metadataIssue = $this->checkMetadataCache($configuration);

                if ($metadataIssue instanceof ConfigurationIssue) {
                    yield $metadataIssue;
                }

                // Check 2: Query Cache (HIGH)
                $queryIssue = $this->checkQueryCache($configuration);

                if ($queryIssue instanceof ConfigurationIssue) {
                    yield $queryIssue;
                }

                // Check 3: Result Cache (MEDIUM)
                $resultIssue = $this->checkResultCache($configuration);

                if ($resultIssue instanceof ConfigurationIssue) {
                    yield $resultIssue;
                }

                // Check 4: Auto Generate Proxy Classes (HIGH)
                $proxyIssue = $this->checkProxyConfiguration($configuration);

                if ($proxyIssue instanceof ConfigurationIssue) {
                    yield $proxyIssue;
                }

                // Check 5: Second Level Cache (if enabled)
                if ($configuration->isSecondLevelCacheEnabled()) {
                    $secondLevelIssue = $this->checkSecondLevelCache($configuration);

                    if ($secondLevelIssue instanceof ConfigurationIssue) {
                        yield $secondLevelIssue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Doctrine Cache Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects suboptimal Doctrine cache configuration (ArrayCache in production causes 50-80% performance loss)';
    }

    /**
     * Check YAML configuration files for production cache issues.
     * This method analyzes config/packages/doctrine.yaml to detect issues
     * even when running in dev/test environments.
     *
     * @return array<ConfigurationIssue>
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function checkYamlConfiguration(): array
    {
        $issues = [];

        if (null === $this->projectDir || '' === $this->projectDir) {
            return $issues;
        }

        $configFile = $this->projectDir . '/config/packages/doctrine.yaml';

        if (!file_exists($configFile)) {
            return $issues;
        }

        try {
            $config = Yaml::parseFile($configFile);

            // Check when@prod section for array cache configuration
            if (isset($config['when@prod']['doctrine']['orm'])) {
                $prodOrmConfig = $config['when@prod']['doctrine']['orm'];

                // Check metadata_cache_driver
                if (isset($prodOrmConfig['metadata_cache_driver']['type'])
                    && 'array' === $prodOrmConfig['metadata_cache_driver']['type']) {
                    $issues[] = $this->createYamlIssue(
                        'critical',
                        'Array Cache in Production Config (Metadata)',
                        'Production configuration (when@prod) uses array cache for metadata. ' .
                        'This will cause entity metadata to be reparsed on EVERY request in production!',
                        'metadata',
                        'array (in when@prod)',
                        '-50 to -80%',
                        $configFile,
                    );
                }

                // Check query_cache_driver
                if (isset($prodOrmConfig['query_cache_driver']['type'])
                    && 'array' === $prodOrmConfig['query_cache_driver']['type']) {
                    $issues[] = $this->createYamlIssue(
                        'critical',
                        'Array Cache in Production Config (Query)',
                        'Production configuration (when@prod) uses array cache for queries. ' .
                        'DQL queries will be recompiled on EVERY execution in production!',
                        'query',
                        'array (in when@prod)',
                        '-30 to -50%',
                        $configFile,
                    );
                }

                // Check result_cache_driver
                if (isset($prodOrmConfig['result_cache_driver']['type'])
                    && 'array' === $prodOrmConfig['result_cache_driver']['type']) {
                    $issues[] = $this->createYamlIssue(
                        'warning',
                        'Array Cache in Production Config (Result)',
                        'Production configuration (when@prod) uses array cache for results. ' .
                        'Query results cannot be cached across requests in production.',
                        'result',
                        'array (in when@prod)',
                        'Varies (0-50%)',
                        $configFile,
                    );
                }
            }
        } catch (\Exception $e) {
            // Ignore YAML parsing errors - file might be malformed
            return $issues;
        }

        return $issues;
    }

    /**
     * Create a cache configuration issue from YAML analysis.
     */
    private function createYamlIssue(
        string $severity,
        string $title,
        string $message,
        string $cacheType,
        string $currentConfig,
        string $performanceImpact,
        string $configFile,
    ): ConfigurationIssue {
        $configurationIssue = new ConfigurationIssue([
            'cache_type'         => $cacheType,
            'current_config'     => $currentConfig,
            'performance_impact' => $performanceImpact,
            'config_file'        => $configFile,
        ]);

        $configurationIssue->setSeverity($severity);
        $configurationIssue->setTitle($title);
        $configurationIssue->setMessage(
            $message . "\n\n" .
            "Configuration file: " . basename($configFile) . "\n" .
            "Performance impact: " . $performanceImpact,
        );

        $suggestion = $this->suggestionFactory->createArrayCacheProduction($cacheType, $currentConfig);

        $configurationIssue->setSuggestion($suggestion);

        return $configurationIssue;
    }

    /**
     * Check metadata cache configuration.
     */
    private function checkMetadataCache(Configuration $configuration): ?ConfigurationIssue
    {
        $cache = $configuration->getMetadataCache();

        if (!$cache instanceof CacheItemPoolInterface) {
            return $this->createIssue(
                'critical',
                'No Metadata Cache Configured',
                'Metadata cache is not configured. Doctrine will reparse all entity metadata on EVERY request!',
                'metadata',
                'none',
                '-70 to -90%',
            );
        }

        if ($this->isArrayCache($cache)) {
            return $this->createIssue(
                'critical',
                'Array Cache in Production (Metadata)',
                'metadata_cache_driver is using ArrayCache. Entity metadata is reparsed on EVERY request, causing massive performance degradation.',
                'metadata',
                'array',
                '-50 to -80%',
            );
        }

        // Check if it's filesystem cache (better than array, but not optimal)
        if ($this->isFilesystemCache($cache)) {
            return $this->createIssue(
                'warning',
                'Filesystem Cache for Metadata (Suboptimal)',
                'metadata_cache_driver is using filesystem cache. Consider using Redis or APCu for better performance.',
                'metadata',
                'filesystem',
                '-20 to -30%',
            );
        }

        return null;
    }

    /**
     * Check query cache configuration.
     */
    private function checkQueryCache(Configuration $configuration): ?ConfigurationIssue
    {
        $cache = $configuration->getQueryCache();

        if (!$cache instanceof CacheItemPoolInterface) {
            return $this->createIssue(
                'warning',
                'No Query Cache Configured',
                'Query cache is not configured. DQL queries are recompiled on EVERY execution!',
                'query',
                'none',
                '-40 to -60%',
            );
        }

        if ($this->isArrayCache($cache)) {
            return $this->createIssue(
                'warning',
                'Array Cache in Production (Query)',
                'query_cache_driver is using ArrayCache. DQL queries are recompiled on EVERY execution instead of being cached.',
                'query',
                'array',
                '-30 to -50%',
            );
        }

        if ($this->isFilesystemCache($cache)) {
            return $this->createIssue(
                'warning',
                'Filesystem Cache for Queries (Suboptimal)',
                'query_cache_driver is using filesystem cache. Consider using Redis or APCu for better performance.',
                'query',
                'filesystem',
                '-10 to -20%',
            );
        }

        return null;
    }

    /**
     * Check result cache configuration.
     */
    private function checkResultCache(Configuration $configuration): ?ConfigurationIssue
    {
        $cache = $configuration->getResultCache();

        if (!$cache instanceof CacheItemPoolInterface) {
            return $this->createIssue(
                'warning',
                'No Result Cache Configured',
                'Result cache is not configured. Query results cannot be cached, missing optimization opportunities.',
                'result',
                'none',
                'Varies (0-50% for cacheable queries)',
            );
        }

        if ($this->isArrayCache($cache)) {
            return $this->createIssue(
                'warning',
                'Array Cache for Results',
                'result_cache_driver is using ArrayCache. This provides no persistent caching across requests.',
                'result',
                'array',
                'Varies',
            );
        }

        return null;
    }

    /**
     * Check proxy auto-generation configuration.
     */
    private function checkProxyConfiguration(Configuration $configuration): ?ConfigurationIssue
    {
        $autoGenerate = $configuration->getAutoGenerateProxyClasses();

        // In Doctrine 2.x: 0 = never, 1 = always, 2 = on file change
        // In Doctrine 3.x: similar constants
        // auto_generate_proxy_classes should be false/0 in production

        if (in_array($autoGenerate, [true, 1, 2], true)) {
            $configurationIssue = new ConfigurationIssue([
                'cache_type'         => 'proxy',
                'current_config'     => 'auto_generate: true',
                'recommended_config' => 'auto_generate: false',
            ]);

            $configurationIssue->setSeverity('critical');
            $configurationIssue->setTitle('Proxy Auto-Generation Enabled in Production');
            $configurationIssue->setMessage(
                'auto_generate_proxy_classes is enabled in production. ' .
                'Doctrine checks filesystem on every request to regenerate proxy classes. ' .
                'This should be disabled in production (set to false).',
            );

            $suggestion = $this->suggestionFactory->createProxyAutoGenerate();

            $configurationIssue->setSuggestion($suggestion);

            return $configurationIssue;
        }

        return null;
    }

    /**
     * Check second level cache configuration.
     */
    private function checkSecondLevelCache(Configuration $configuration): ?ConfigurationIssue
    {
        $cacheConfig = $configuration->getSecondLevelCacheConfiguration();

        if (!$cacheConfig instanceof CacheConfiguration) {
            return null;
        }

        $cacheFactory = $cacheConfig->getCacheFactory();

        if (!$cacheFactory instanceof CacheFactory) {
            return null;
        }

        // Try to get the cache implementation (reflection)
        try {
            $reflectionClass  = new ReflectionClass($cacheFactory::class);
            $regionsCacheProp = $reflectionClass->getProperty('regionsConfiguration');
            $regionsCacheProp->getValue($cacheFactory);

            // This is a simplified check - full implementation would be more complex
            // For now, we just warn if second level cache is enabled but might be using array

            return $this->createIssue(
                'warning',
                'Second Level Cache Configuration',
                'Second level cache is enabled. Ensure it uses Redis/APCu and not ArrayCache.',
                'second_level',
                'unknown',
                'Varies',
            );
        } catch (\Exception) {
            // Can't determine - skip
            return null;
        }
    }

    /**
     * Check if cache is ArrayCache.
     */
    private function isArrayCache(CacheItemPoolInterface $cacheItemPool): bool
    {
        $className = $cacheItemPool::class;

        foreach (self::ARRAY_CACHE_CLASSES as $arrayClass) {
            if ($className === $arrayClass || is_subclass_of($className, $arrayClass)) {
                return true;
            }
        }

        // Check for Symfony ArrayAdapter
        return str_contains($className, 'ArrayAdapter');
    }

    /**
     * Check if cache is FilesystemCache.
     */
    private function isFilesystemCache(CacheItemPoolInterface $cacheItemPool): bool
    {
        $className = $cacheItemPool::class;

        return str_contains($className, 'FilesystemCache')
               || str_contains($className, 'FilesystemAdapter')
               || str_contains($className, 'PhpFilesAdapter');
    }

    /**
     * Create a cache configuration issue.
     */
    private function createIssue(
        string $severity,
        string $title,
        string $message,
        string $cacheType,
        string $currentConfig,
        string $performanceImpact,
    ): ConfigurationIssue {
        $configurationIssue = new ConfigurationIssue([
            'cache_type'         => $cacheType,
            'current_config'     => $currentConfig,
            'performance_impact' => $performanceImpact,
        ]);

        $configurationIssue->setSeverity($severity);
        $configurationIssue->setTitle($title);
        $configurationIssue->setMessage($message . ' Performance impact: ' . $performanceImpact);

        $suggestion = $this->suggestionFactory->createArrayCacheProduction($cacheType, $currentConfig);

        $configurationIssue->setSuggestion($suggestion);

        return $configurationIssue;
    }
}
