<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Collector;

use AhmedBhs\DoctrineDoctor\Collector\DataCollectorHelpers;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Unit tests for DoctrineDoctorDataCollector.
 * Tests the path exclusion filtering optimization.
 */
final class DoctrineDoctorDataCollectorTest extends TestCase
{
    #[Test]
    #[DataProvider('filterQueriesByPathsProvider')]
    public function it_filters_queries_by_paths_correctly(
        array $queries,
        array $excludePaths,
        int $expectedCount,
        string $description,
    ): void {
        $collector = $this->createDataCollector();

        // Use reflection to access private method
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('filterQueriesByPaths');
        $method->setAccessible(true);

        $filtered = $method->invoke($collector, $queries, $excludePaths);

        self::assertCount(
            $expectedCount,
            $filtered,
            sprintf('Expected %d queries after filtering, got %d. Test case: %s', $expectedCount, count($filtered), $description)
        );
    }

    /**
     * @return array<string, array{queries: array, excludePaths: array, expectedCount: int, description: string}>
     */
    public static function filterQueriesByPathsProvider(): array
    {
        return [
            'no_exclusion_empty_paths' => [
                'queries' => [
                    self::createQuery('/app/src/Controller/UserController.php'),
                    self::createQuery('/app/src/Repository/UserRepository.php'),
                ],
                'excludePaths' => [],
                'expectedCount' => 2,
                'description' => 'No paths to exclude should keep all queries',
            ],

            'exclude_vendor_queries' => [
                'queries' => [
                    self::createQuery('/app/src/Controller/UserController.php'),
                    self::createQuery('/app/vendor/symfony/http-kernel/HttpKernel.php'),
                    self::createQuery('/app/src/Service/OrderService.php'),
                    self::createQuery('/app/vendor/doctrine/orm/EntityManager.php'),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 2,
                'description' => 'Should exclude queries from vendor/',
            ],

            'exclude_multiple_paths' => [
                'queries' => [
                    self::createQuery('/app/src/Controller/UserController.php'),
                    self::createQuery('/app/vendor/symfony/http-kernel/HttpKernel.php'),
                    self::createQuery('/app/var/cache/dev/Container.php'),
                    self::createQuery('/app/src/Service/OrderService.php'),
                ],
                'excludePaths' => ['vendor/', 'var/cache/'],
                'expectedCount' => 2,
                'description' => 'Should exclude queries from vendor/ and var/cache/',
            ],

            'no_backtrace_included' => [
                'queries' => [
                    ['sql' => 'SELECT * FROM users'],
                    self::createQuery('/app/src/Controller/UserController.php'),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 2,
                'description' => 'Queries without backtrace should be included (conservative approach)',
            ],

            'empty_backtrace_included' => [
                'queries' => [
                    ['sql' => 'SELECT * FROM users', 'backtrace' => []],
                    self::createQuery('/app/src/Controller/UserController.php'),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 2,
                'description' => 'Queries with empty backtrace should be included',
            ],

            'windows_paths' => [
                'queries' => [
                    self::createQuery('C:\\app\\src\\Controller\\UserController.php'),
                    self::createQuery('C:\\app\\vendor\\symfony\\http-kernel\\HttpKernel.php'),
                ],
                'excludePaths' => ['vendor\\'],
                'expectedCount' => 1,
                'description' => 'Should handle Windows-style paths with backslashes',
            ],

            'mixed_paths_normalized' => [
                'queries' => [
                    self::createQuery('/app/src/Controller/UserController.php'),
                    self::createQuery('C:\\app\\vendor\\symfony\\http-kernel\\HttpKernel.php'),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 1,
                'description' => 'Should normalize paths (/ and \\) for comparison',
            ],

            'partial_path_match' => [
                'queries' => [
                    self::createQuery('/app/src/vendor_lib/CustomLib.php'),
                    self::createQuery('/app/vendor/symfony/HttpKernel.php'),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 1,
                'description' => 'Should match substring (vendor_lib contains vendor but is not vendor/)',
            ],

            'deep_backtrace_vendor_in_middle' => [
                'queries' => [
                    self::createQueryWithMultipleFrames([
                        '/app/src/Controller/UserController.php',
                        '/app/vendor/symfony/http-kernel/HttpKernel.php',
                        '/app/src/Kernel.php',
                    ]),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 1,
                'description' => 'SMART FILTERING: Should INCLUDE because first frame is from app code (UserController)',
            ],

            'all_app_code_no_vendor' => [
                'queries' => [
                    self::createQueryWithMultipleFrames([
                        '/app/src/Controller/UserController.php',
                        '/app/src/Service/UserService.php',
                        '/app/src/Repository/UserRepository.php',
                    ]),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 1,
                'description' => 'Should include queries from app code only',
            ],

            'smart_filtering_app_then_vendor' => [
                'queries' => [
                    self::createQueryWithMultipleFrames([
                        '/app/src/Controller/ProductController.php',
                        '/app/vendor/doctrine/orm/Query.php',
                        '/app/vendor/doctrine/dbal/Connection.php',
                    ]),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 1,
                'description' => 'SMART: First frame is app code, include despite vendor frames below',
            ],

            'smart_filtering_all_vendor' => [
                'queries' => [
                    self::createQueryWithMultipleFrames([
                        '/app/vendor/some-bundle/EventSubscriber.php',
                        '/app/vendor/symfony/http-kernel/HttpKernel.php',
                        '/app/vendor/doctrine/orm/EntityManager.php',
                    ]),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 0,
                'description' => 'SMART: All frames are vendor, exclude query',
            ],

            'smart_filtering_cache_then_app' => [
                'queries' => [
                    self::createQueryWithMultipleFrames([
                        '/app/var/cache/dev/Container.php',
                        '/app/src/Service/CacheWarmer.php',
                        '/app/vendor/symfony/cache/Adapter.php',
                    ]),
                ],
                'excludePaths' => ['vendor/', 'var/cache/'],
                'expectedCount' => 1,
                'description' => 'SMART: First non-excluded frame is CacheWarmer (app code), include',
            ],

            'smart_filtering_vendor_at_start' => [
                'queries' => [
                    self::createQueryWithMultipleFrames([
                        '/app/vendor/doctrine/orm/UnitOfWork.php',
                        '/app/src/Repository/OrderRepository.php',
                        '/app/src/Controller/OrderController.php',
                    ]),
                ],
                'excludePaths' => ['vendor/'],
                'expectedCount' => 1,
                'description' => 'SMART: Skip vendor frame, first app frame is OrderRepository, include',
            ],
        ];
    }

    #[Test]
    public function it_handles_null_backtrace(): void
    {
        $collector = $this->createDataCollector();
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('isQueryFromExcludedPaths');
        $method->setAccessible(true);

        $query = ['sql' => 'SELECT * FROM users', 'backtrace' => null];
        $result = $method->invoke($collector, $query, ['vendor/']);

        self::assertFalse($result, 'Null backtrace should not be excluded');
    }

    #[Test]
    public function it_handles_invalid_backtrace_frames(): void
    {
        $collector = $this->createDataCollector();
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('isQueryFromExcludedPaths');
        $method->setAccessible(true);

        $query = [
            'sql' => 'SELECT * FROM users',
            'backtrace' => [
                'invalid_frame', // Not an array
                ['file' => 123], // File is not a string
                ['line' => 42], // No file key
            ],
        ];

        $result = $method->invoke($collector, $query, ['vendor/']);

        self::assertFalse($result, 'Invalid backtrace frames should be skipped gracefully');
    }

    /**
     * Helper to create a query with backtrace.
     */
    private static function createQuery(string $file): array
    {
        return [
            'sql' => 'SELECT * FROM users',
            'backtrace' => [
                ['file' => $file, 'line' => 42],
            ],
        ];
    }

    /**
     * Helper to create a query with multiple backtrace frames.
     */
    private static function createQueryWithMultipleFrames(array $files): array
    {
        $backtrace = [];
        foreach ($files as $file) {
            $backtrace[] = ['file' => $file, 'line' => 42];
        }

        return [
            'sql' => 'SELECT * FROM users',
            'backtrace' => $backtrace,
        ];
    }

    /**
     * Create a minimal DoctrineDoctorDataCollector instance for testing.
     */
    private function createDataCollector(): DoctrineDoctorDataCollector
    {
        // Create real instances of helper services (simpler than mocking final classes)
        $logger = new NullLogger();
        $helpers = new DataCollectorHelpers(
            databaseInfoCollector: new \AhmedBhs\DoctrineDoctor\Collector\Helper\DatabaseInfoCollector(
                logger: $logger,
            ),
            issueReconstructor: new \AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor(),
            queryStatsCalculator: new \AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator(),
            dataCollectorLogger: new \AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger(
                logger: $logger,
            ),
            issueDeduplicator: new \AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator(),
        );

        return new DoctrineDoctorDataCollector(
            analyzers: [],
            doctrineDataCollector: null,
            entityManager: null,
            stopwatch: null,
            showDebugInfo: false,
            dataCollectorHelpers: $helpers,
            excludePaths: ['vendor/'],
        );
    }
}
