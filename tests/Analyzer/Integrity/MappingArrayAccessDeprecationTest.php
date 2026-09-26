<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\NullablePrimaryKeyAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\SingleTableInheritanceNullableColumnAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\StringDefaultExpressionAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\UniqueEntityWithoutDatabaseIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ORM 3.1 deprecates ArrayAccess on mapping objects (doctrine/orm#11211) and
 * ORM 4.0 removes it: analyzers must read mappings through their properties.
 */
final class MappingArrayAccessDeprecationTest extends TestCase
{
    use VerifyDeprecations;

    private const string MAPPING_ARRAY_ACCESS = 'https://github.com/doctrine/orm/pull/11211';

    /**
     * @param \Closure(): AnalyzerInterface $createAnalyzer
     */
    #[Test]
    #[DataProvider('provideAnalyzers')]
    public function it_reads_mappings_without_deprecated_array_access(\Closure $createAnalyzer): void
    {
        $analyzer = $createAnalyzer();

        $this->expectNoDeprecationWithIdentifier(self::MAPPING_ARRAY_ACCESS);

        $analyzer->analyze(QueryDataCollection::empty())->toArray();
    }

    /**
     * @return iterable<string, array{\Closure(): AnalyzerInterface}>
     */
    public static function provideAnalyzers(): iterable
    {
        $fixtures = \dirname(__DIR__, 2) . '/Fixtures/Entity';

        yield 'unique entity without index' => [static fn (): AnalyzerInterface => new UniqueEntityWithoutDatabaseIndexAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager([$fixtures . '/UniqueEntityTest']),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        )];

        yield 'string default expression' => [static fn (): AnalyzerInterface => new StringDefaultExpressionAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager([$fixtures . '/MappingDeprecationTest']),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        )];

        yield 'single table inheritance nullable column' => [static fn (): AnalyzerInterface => new SingleTableInheritanceNullableColumnAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager([$fixtures . '/InheritanceIntegrityTest']),
        )];

        yield 'nullable primary key' => [static fn (): AnalyzerInterface => new NullablePrimaryKeyAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager([$fixtures . '/MappingDeprecationTest']),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        )];
    }
}
