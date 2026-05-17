<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzerConfig;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MissingIndexAnalyzerSQLiteLeakTest extends TestCase
{
    #[Test]
    public function it_must_suggest_index_when_sqlite_full_scan_above_production_threshold(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('
            CREATE TABLE products (
                id INTEGER PRIMARY KEY,
                name TEXT,
                archived INTEGER DEFAULT 0
            )
        ');

        for ($i = 1; $i <= 5000; $i++) {
            $connection->executeStatement(
                'INSERT INTO products VALUES (?, ?, ?)',
                [$i, "Product {$i}", $i % 2],
            );
        }

        $config = new MissingIndexAnalyzerConfig(
            enabled: true,
            slowQueryThreshold: 50,
            minRowsScanned: 1000,
            maxRowsForAcceptableFilesort: 10,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: new SuggestionFactory(
                new PhpTemplateRenderer(__DIR__ . '/../../../src/Template/Suggestions'),
            ),
            connection: $connection,
            missingIndexAnalyzerConfig: $config,
        );

        $query = new QueryData(
            sql: 'SELECT * FROM products WHERE archived = 0',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
            backtrace: [['file' => __FILE__, 'line' => __LINE__]],
        );

        $issues = iterator_to_array($analyzer->analyze(QueryDataCollection::fromArray([$query])));

        self::assertGreaterThanOrEqual(
            1,
            count($issues),
            'SQLite leak: hardcoded row estimate of 100 prevents detection on tables of 1000+ rows. '
            . 'Real production tables can have millions of rows. Need real COUNT or sqlite_stat1.',
        );
    }
}
