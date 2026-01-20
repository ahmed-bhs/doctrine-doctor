<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\DoctrineCacheAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use Doctrine\ORM\Configuration;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Comprehensive tests for DoctrineCacheAnalyzer.
 *
 * The DoctrineCacheAnalyzer detects suboptimal Doctrine cache configuration in production:
 * - Missing metadata cache (reparse entities on every request = -50-80% perf)
 * - Missing query cache (recompile DQL on every execution = -30-50% perf)
 * - Missing result cache (no caching of query results)
 * - ArrayCache in production (data lost after each request)
 * - FilesystemCache (slower than Redis/APCu)
 * - Proxy auto-generation enabled in production
 * - Second level cache misconfiguration
 */
final class DoctrineCacheAnalyzerTest extends DatabaseTestCase
{
    private DoctrineCacheAnalyzer $analyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        // Default analyzer with prod environment and suggestion factory
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $this->analyzer = new DoctrineCacheAnalyzer($this->entityManager, $suggestionFactory, 'prod');
    }

    #[Test]
    public function it_detects_array_cache_for_metadata(): void
    {
        // Arrange: Configure with ArrayAdapter for metadata
        $configuration = $this->entityManager->getConfiguration();
        $arrayCache = new ArrayAdapter();
        $configuration->setMetadataCache($arrayCache);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $metadataIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Array Cache')
                && str_contains($issue->getTitle(), 'Metadata'),
        );

        self::assertNotEmpty($metadataIssues, 'Should detect ArrayCache for metadata');

        $issue = reset($metadataIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertSame('critical', $issue->getSeverity()->value, 'Metadata ArrayCache should be CRITICAL');
        self::assertStringContainsString('ArrayCache', $issue->getDescription());
        self::assertStringContainsString('metadata', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_detects_array_cache_for_query(): void
    {
        // Arrange: Configure with proper metadata cache but ArrayAdapter for query
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache($this->createMockRedisCache());
        $configuration->setQueryCache(new ArrayAdapter());

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $queryIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Array Cache')
                && str_contains($issue->getTitle(), 'Query'),
        );

        self::assertNotEmpty($queryIssues, 'Should detect ArrayCache for query');

        $issue = reset($queryIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertSame('warning', $issue->getSeverity()->value, 'Query ArrayCache should be HIGH severity (-40 to -60% perf impact)');
        self::assertStringContainsString('query', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_detects_array_cache_for_result(): void
    {
        // Arrange: Configure with proper caches but ArrayAdapter for result
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache($this->createMockRedisCache());
        $configuration->setQueryCache($this->createMockRedisCache());
        $configuration->setResultCache(new ArrayAdapter());

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $resultIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Array Cache')
                && str_contains($issue->getTitle(), 'Results'),
        );

        self::assertNotEmpty($resultIssues, 'Should detect ArrayCache for result');

        $issue = reset($resultIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertSame('warning', $issue->getSeverity()->value, 'Result ArrayCache should be WARNING severity');
        self::assertStringContainsString('result', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_detects_filesystem_cache_for_metadata(): void
    {
        // Arrange: Configure with FilesystemAdapter for metadata
        $configuration = $this->entityManager->getConfiguration();
        $filesystemCache = new FilesystemAdapter('test', 0, sys_get_temp_dir());
        $configuration->setMetadataCache($filesystemCache);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $filesystemIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Filesystem Cache')
                && str_contains($issue->getTitle(), 'Metadata'),
        );

        self::assertNotEmpty($filesystemIssues, 'Should detect FilesystemCache for metadata');

        $issue = reset($filesystemIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertSame('warning', $issue->getSeverity()->value, 'Filesystem cache should be WARNING severity');
        self::assertStringContainsString('filesystem', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_detects_filesystem_cache_for_query(): void
    {
        // Arrange: Configure with proper metadata but FilesystemAdapter for query
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache($this->createMockRedisCache());
        $configuration->setQueryCache(new FilesystemAdapter('test', 0, sys_get_temp_dir()));

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $filesystemIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Filesystem Cache')
                && str_contains($issue->getTitle(), 'Queries'),
        );

        self::assertNotEmpty($filesystemIssues, 'Should detect FilesystemCache for query');

        $issue = reset($filesystemIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertSame('warning', $issue->getSeverity()->value);
        self::assertStringContainsString('query', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_detects_proxy_auto_generation_enabled(): void
    {
        // Arrange: Enable proxy auto-generation
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache($this->createMockRedisCache());
        $configuration->setQueryCache($this->createMockRedisCache());
        $configuration->setResultCache($this->createMockRedisCache());
        $configuration->setAutoGenerateProxyClasses(true);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $proxyIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Proxy Auto-Generation'),
        );

        self::assertNotEmpty($proxyIssues, 'Should detect proxy auto-generation enabled');

        $issue = reset($proxyIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertSame('critical', $issue->getSeverity()->value, 'Proxy auto-generation should be CRITICAL');
        self::assertStringContainsString('proxy', strtolower($issue->getDescription()));
        self::assertStringContainsString('auto_generate', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_detects_multiple_cache_issues_simultaneously(): void
    {
        // Arrange: Configure with multiple problems
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache(new ArrayAdapter()); // ArrayCache (critical)
        $configuration->setQueryCache(new ArrayAdapter()); // ArrayCache (high)
        $configuration->setResultCache(new ArrayAdapter()); // ArrayCache (medium)
        $configuration->setAutoGenerateProxyClasses(true); // Enabled (critical)

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect all 4 issues
        $issuesArray = $issues->toArray();

        self::assertGreaterThanOrEqual(4, count($issuesArray), 'Should detect multiple cache issues');

        // Check that we have metadata, query, result, and proxy issues
        $titles = array_map(fn ($issue) => $issue->getTitle(), $issuesArray);
        $hasMeta = array_filter($titles, fn ($t) => str_contains($t, 'Metadata'));
        $hasQuery = array_filter($titles, fn ($t) => str_contains($t, 'Query'));
        $hasResult = array_filter($titles, fn ($t) => str_contains($t, 'Result'));
        $hasProxy = array_filter($titles, fn ($t) => str_contains($t, 'Proxy'));

        self::assertNotEmpty($hasMeta, 'Should have metadata cache issue');
        self::assertNotEmpty($hasQuery, 'Should have query cache issue');
        self::assertNotEmpty($hasResult, 'Should have result cache issue');
        self::assertNotEmpty($hasProxy, 'Should have proxy issue');
    }

    #[Test]
    public function it_does_not_flag_optimal_cache_configuration(): void
    {
        // Arrange: Configure with optimal settings
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache($this->createMockRedisCache());
        $configuration->setQueryCache($this->createMockRedisCache());
        $configuration->setResultCache($this->createMockRedisCache());
        $configuration->setAutoGenerateProxyClasses(false);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT report any issues with optimal config
        $issuesArray = $issues->toArray();

        self::assertCount(0, $issuesArray, 'Should NOT flag optimal cache configuration');
    }

    #[Test]
    public function it_only_analyzes_in_production_environment(): void
    {
        // Arrange: Create analyzer with test environment
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $testAnalyzer = new DoctrineCacheAnalyzer($this->entityManager, $suggestionFactory, 'test');

        // Configure with problems that would be detected in prod
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache(new ArrayAdapter());
        $configuration->setQueryCache(new ArrayAdapter());

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $testAnalyzer->analyze($queries);

        // Assert: Should NOT analyze in test environment
        $issuesArray = $issues->toArray();
        self::assertCount(0, $issuesArray, 'Should not analyze cache in test environment');
    }

    #[Test]
    public function it_skips_analysis_in_dev_environment(): void
    {
        // Arrange: Create analyzer with dev environment
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $devAnalyzer = new DoctrineCacheAnalyzer($this->entityManager, $suggestionFactory, 'dev');

        // Configure with problems
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache(new ArrayAdapter());
        $configuration->setAutoGenerateProxyClasses(true);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $devAnalyzer->analyze($queries);

        // Assert: Should NOT analyze in dev environment
        $issuesArray = $issues->toArray();
        self::assertCount(0, $issuesArray, 'Should not analyze cache in dev environment');
    }

    #[Test]
    public function it_provides_comprehensive_suggestions(): void
    {
        // Arrange: Configure with ArrayCache for metadata
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache(new ArrayAdapter());

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertNotEmpty($issuesArray, 'Should have issues to check suggestions');

        $issue = reset($issuesArray);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface::class, $suggestion);

        $suggestionText = $suggestion->getCode();

        // Check that suggestions include Redis/APCu recommendations
        self::assertStringContainsString('Redis', $suggestionText, 'Should mention Redis');
        self::assertStringContainsString('APCu', $suggestionText, 'Should mention APCu');

        // Check for configuration
        self::assertStringContainsString('cache', strtolower($suggestionText), 'Should mention cache');
    }

    #[Test]
    public function it_provides_correct_severity_levels(): void
    {
        // Arrange: Test different severities
        $configuration = $this->entityManager->getConfiguration();

        // Test 1: CRITICAL - metadata array cache
        $configuration->setMetadataCache(new ArrayAdapter());
        $configuration->setQueryCache($this->createMockRedisCache());
        $configuration->setResultCache($this->createMockRedisCache());

        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $criticalIssues = array_filter(
            $issuesArray,
            fn ($issue) => 'critical' === $issue->getSeverity()->value,
        );

        self::assertNotEmpty($criticalIssues, 'Should have CRITICAL issues for metadata ArrayCache');

        // Test 2: WARNING - filesystem cache
        $configuration->setMetadataCache(new FilesystemAdapter('test', 0, sys_get_temp_dir()));
        $issues2 = $this->analyzer->analyze($queries);
        $issuesArray2 = $issues2->toArray();

        $warningIssues = array_filter(
            $issuesArray2,
            fn ($issue) => 'warning' === $issue->getSeverity()->value,
        );

        self::assertNotEmpty($warningIssues, 'Should have WARNING issues for filesystem cache');
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: IssueCollection uses generator pattern
        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_returns_issue_collection(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertIsObject($issues);
        self::assertIsIterable($issues);
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_has_descriptive_name_and_description(): void
    {
        // Act
        $name = $this->analyzer->getName();
        $description = $this->analyzer->getDescription();

        // Assert
        self::assertNotEmpty($name);
        self::assertStringContainsString('Cache', $name);
        self::assertStringContainsString('Doctrine', $name);

        self::assertNotEmpty($description);
        self::assertStringContainsString('cache', strtolower($description));
        self::assertStringContainsString('performance', strtolower($description));
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertIsObject($issues);
        self::assertIsIterable($issues);
    }

    #[Test]
    public function it_does_not_throw_on_analysis(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act & Assert: Should not throw exceptions
        $this->expectNotToPerformAssertions();
        $this->analyzer->analyze($queries);
    }

    #[Test]
    public function it_handles_proxy_auto_generation_with_value_1(): void
    {
        // Arrange: Set auto-generate to 1 (always)
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache($this->createMockRedisCache());
        $configuration->setQueryCache($this->createMockRedisCache());
        $configuration->setResultCache($this->createMockRedisCache());
        $configuration->setAutoGenerateProxyClasses(1);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $proxyIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Proxy'),
        );

        self::assertNotEmpty($proxyIssues, 'Should detect auto_generate = 1');
    }

    #[Test]
    public function it_handles_proxy_auto_generation_with_value_2(): void
    {
        // Arrange: Set auto-generate to 2 (on file change)
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache($this->createMockRedisCache());
        $configuration->setQueryCache($this->createMockRedisCache());
        $configuration->setResultCache($this->createMockRedisCache());
        $configuration->setAutoGenerateProxyClasses(2);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $proxyIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Proxy'),
        );

        self::assertNotEmpty($proxyIssues, 'Should detect auto_generate = 2');
    }

    #[Test]
    public function it_handles_proxy_auto_generation_disabled(): void
    {
        // Arrange: Disable proxy auto-generation (proper production config)
        $configuration = $this->entityManager->getConfiguration();
        $configuration->setMetadataCache($this->createMockRedisCache());
        $configuration->setQueryCache($this->createMockRedisCache());
        $configuration->setResultCache($this->createMockRedisCache());
        $configuration->setAutoGenerateProxyClasses(false);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $proxyIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Proxy'),
        );

        self::assertCount(0, $proxyIssues, 'Should NOT flag when proxy auto-generation is disabled');
    }

    #[Test]
    public function it_handles_exceptions_gracefully(): void
    {
        // Arrange: This test ensures analyzer doesn't crash
        $queries = QueryDataBuilder::create()->build();

        // Act & Assert: Should not throw exceptions
        $this->expectNotToPerformAssertions();
        $this->analyzer->analyze($queries);
    }

    /**
     * Create a mock Redis cache adapter that is considered optimal.
     */
    private function createMockRedisCache(): CacheItemPoolInterface
    {
        // Create a mock that looks like Redis but doesn't require Redis to be installed
        return new class() implements CacheItemPoolInterface {
            /**
             * @throws void
             */
            public function getItem(string $key): CacheItemInterface
            {
                return new class() implements CacheItemInterface {
                    public function getKey(): string
                    {
                        return 'test';
                    }

                    public function get(): mixed
                    {
                        return null;
                    }

                    public function isHit(): bool
                    {
                        return false;
                    }

                    public function set(mixed $value): static
                    {
                        return $this;
                    }

                    public function expiresAt(?\DateTimeInterface $expiration): static
                    {
                        return $this;
                    }

                    public function expiresAfter(\DateInterval|int|null $time): static
                    {
                        return $this;
                    }
                };
            }

            /**
             * @throws void
             */
            public function getItems(array $keys = []): iterable
            {
                return [];
            }

            /**
             * @throws void
             */
            public function hasItem(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return true;
            }

            /**
             * @throws void
             */
            public function deleteItem(string $key): bool
            {
                return true;
            }

            /**
             * @throws void
             */
            public function deleteItems(array $keys): bool
            {
                return true;
            }

            public function save(CacheItemInterface $item): bool
            {
                return true;
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }
        };
    }
}
