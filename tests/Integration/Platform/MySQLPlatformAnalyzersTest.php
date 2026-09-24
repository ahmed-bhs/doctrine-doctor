<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Platform;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\CharsetAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\CollationAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\ConnectionPoolingAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\InnoDBEngineAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\StrictModeAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\TimeZoneAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Security\OverprivilegedDatabaseUserAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the platform-specific analyzers against a real MySQL or MariaDB server.
 * Configure it with DB_HOST, DB_PORT, DB_USER, DB_PASSWORD and DB_NAME; the
 * tests are skipped when no server is reachable.
 */
final class MySQLPlatformAnalyzersTest extends TestCase
{
    private const array TABLES = ['dd_platform_myisam', 'dd_platform_utf8mb3', 'dd_platform_bin', 'dd_platform_orders', 'dd_platform_codes'];

    private Connection $connection;

    protected function setUp(): void
    {
        try {
            $this->connection = PlatformAnalyzerTestHelper::createMySQLConnection();
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $throwable) {
            self::markTestSkipped('MySQL is not reachable: ' . $throwable->getMessage());
        }

        $this->dropTables();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropTables();
            $this->connection->close();
        }
    }

    /**
     * @param class-string<MetadataAnalyzerInterface> $analyzerClass
     */
    #[Test]
    #[DataProvider('provideAnalyzers')]
    public function it_runs_against_a_real_server(string $analyzerClass): void
    {
        foreach (PlatformAnalyzerTestHelper::createPlatformAnalyzer($this->connection, $analyzerClass)->analyzeMetadata() as $issue) {
            self::assertNotSame('', $issue->getTitle());
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{class-string<MetadataAnalyzerInterface>}>
     */
    public static function provideAnalyzers(): iterable
    {
        foreach ([CharsetAnalyzer::class, CollationAnalyzer::class, ConnectionPoolingAnalyzer::class, InnoDBEngineAnalyzer::class, StrictModeAnalyzer::class, TimeZoneAnalyzer::class, OverprivilegedDatabaseUserAnalyzer::class] as $class) {
            yield substr(strrchr($class, '\\') ?: $class, 1) => [$class];
        }
    }

    #[Test]
    public function it_reports_a_table_not_using_innodb(): void
    {
        $this->connection->executeStatement('CREATE TABLE dd_platform_myisam (id INT PRIMARY KEY) ENGINE=MyISAM');

        self::assertContains('1 tables not using InnoDB engine', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, InnoDBEngineAnalyzer::class));
    }

    #[Test]
    public function it_reports_a_table_using_utf8mb3(): void
    {
        $this->connection->executeStatement('CREATE TABLE dd_platform_utf8mb3 (id INT PRIMARY KEY, name VARCHAR(10)) ENGINE=InnoDB CHARACTER SET utf8mb3');

        self::assertContains('1 tables using utf8 charset', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, CharsetAnalyzer::class));
    }

    #[Test]
    public function it_reports_a_table_collation_differing_from_the_database(): void
    {
        $this->connection->executeStatement('CREATE TABLE dd_platform_bin (id INT PRIMARY KEY, name VARCHAR(10)) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');

        self::assertContains('1 tables with different collation than database', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, CollationAnalyzer::class));
    }

    #[Test]
    public function it_reports_missing_strict_mode_only_when_the_session_lacks_it(): void
    {
        $this->connection->executeStatement("SET SESSION sql_mode = ''");
        self::assertContains('Missing SQL Strict Mode Settings', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, StrictModeAnalyzer::class));

        $this->connection->executeStatement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::assertNotContains('Missing SQL Strict Mode Settings', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, StrictModeAnalyzer::class));
    }

    #[Test]
    public function it_reports_a_missing_index_only_when_no_index_can_serve_the_filter(): void
    {
        $this->connection->executeStatement('CREATE TABLE dd_platform_orders (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, INDEX idx_created_at (created_at)) ENGINE=InnoDB');
        $this->connection->executeStatement(
            'INSERT INTO dd_platform_orders (created_at, status) WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 1000) '
            . "SELECT DATE_ADD('2024-01-01', INTERVAL n HOUR), IF(0 = n % 2, 'paid', 'new') FROM seq",
        );
        // Recursive CTEs stop at 1000 iterations by default: double the rows twice.
        foreach ([1000, 2000] as $shift) {
            $this->connection->executeStatement("INSERT INTO dd_platform_orders (created_at, status) SELECT DATE_ADD(created_at, INTERVAL {$shift} HOUR), status FROM dd_platform_orders");
        }

        $this->connection->executeQuery('ANALYZE TABLE dd_platform_orders')->fetchAllAssociative();

        self::assertSame([], $this->missingIndexTitles("SELECT * FROM dd_platform_orders WHERE created_at >= '2024-01-01' AND created_at < '2025-01-01'"));
        self::assertContains('Missing Index Detected', $this->missingIndexTitles("SELECT * FROM dd_platform_orders WHERE status = 'paid'"));
    }

    #[Test]
    public function it_loses_the_index_only_when_a_text_column_is_compared_to_a_number(): void
    {
        $this->connection->executeStatement('CREATE TABLE dd_platform_codes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, code VARCHAR(20) NOT NULL, INDEX idx_user_id (user_id), INDEX idx_code (code)) ENGINE=InnoDB');
        $this->connection->executeStatement(
            'INSERT INTO dd_platform_codes (user_id, code) WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 1000) SELECT n, CAST(n AS CHAR) FROM seq',
        );
        $this->connection->executeQuery('ANALYZE TABLE dd_platform_codes')->fetchAllAssociative();

        self::assertNotNull($this->explainKey("SELECT * FROM dd_platform_codes WHERE user_id = '42'"));
        self::assertNotNull($this->explainKey("SELECT * FROM dd_platform_codes WHERE code = '123'"));
        self::assertNull($this->explainKey('SELECT * FROM dd_platform_codes WHERE code = 123'));
    }

    private function explainKey(string $sql): ?string
    {
        $key = $this->connection->fetchAssociative('EXPLAIN ' . $sql)['key'] ?? null;

        return is_string($key) ? $key : null;
    }

    /**
     * @return list<string>
     */
    private function missingIndexTitles(string $sql): array
    {
        $analyzer = new MissingIndexAnalyzer(PlatformAnalyzerTestHelper::createSuggestionFactory(), $this->connection);
        $titles   = [];

        foreach ($analyzer->analyze(QueryDataBuilder::create()->addQuery($sql, 0.1)->build()) as $issue) {
            $titles[] = $issue->getTitle();
        }

        return $titles;
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
