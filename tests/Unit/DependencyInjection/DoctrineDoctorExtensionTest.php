<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection;

use AhmedBhs\DoctrineDoctor\DependencyInjection\DoctrineDoctorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
}
