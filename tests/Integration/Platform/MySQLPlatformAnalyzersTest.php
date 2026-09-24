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
use AhmedBhs\DoctrineDoctor\Analyzer\Security\OverprivilegedDatabaseUserAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
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
    private const array TABLES = ['dd_platform_myisam', 'dd_platform_utf8mb3', 'dd_platform_bin'];

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

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
