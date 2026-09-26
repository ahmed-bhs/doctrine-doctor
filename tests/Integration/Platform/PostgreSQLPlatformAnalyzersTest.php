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
 * Runs the platform-specific analyzers against a real PostgreSQL server.
 * Configure it with PG_HOST, PG_PORT, PG_USER, PG_PASSWORD and PG_NAME; the
 * tests are skipped when no server is reachable.
 */
final class PostgreSQLPlatformAnalyzersTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        try {
            $this->connection = PlatformAnalyzerTestHelper::createPostgreSQLConnection();
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $throwable) {
            self::markTestSkipped('PostgreSQL is not reachable: ' . $throwable->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS dd_platform_orders');
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
    public function it_reports_a_missing_statement_timeout_only_when_the_session_has_none(): void
    {
        $this->connection->executeStatement('SET statement_timeout = 0');
        self::assertContains('No statement timeout configured', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, ConnectionPoolingAnalyzer::class));

        $this->connection->executeStatement("SET statement_timeout = '30s'");
        self::assertNotContains('No statement timeout configured', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, ConnectionPoolingAnalyzer::class));
    }

    #[Test]
    public function it_reports_a_missing_idle_transaction_timeout_only_when_the_session_has_none(): void
    {
        $this->connection->executeStatement('SET idle_in_transaction_session_timeout = 0');
        self::assertContains('No timeout for idle transactions (CRITICAL)', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, ConnectionPoolingAnalyzer::class));

        $this->connection->executeStatement("SET idle_in_transaction_session_timeout = '1min'");
        self::assertNotContains('No timeout for idle transactions (CRITICAL)', PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, ConnectionPoolingAnalyzer::class));
    }

    #[Test]
    public function it_does_not_apply_mysql_only_checks(): void
    {
        self::assertSame([], PlatformAnalyzerTestHelper::platformAnalyzerTitles($this->connection, InnoDBEngineAnalyzer::class));
    }

    #[Test]
    public function it_reports_a_missing_index_only_when_no_index_can_serve_the_filter(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS dd_platform_orders');
        $this->connection->executeStatement('CREATE TABLE dd_platform_orders (id SERIAL PRIMARY KEY, created_at TIMESTAMP NOT NULL, status VARCHAR(20) NOT NULL)');
        $this->connection->executeStatement('CREATE INDEX idx_dd_platform_orders_created_at ON dd_platform_orders (created_at)');
        $this->connection->executeStatement(
            "INSERT INTO dd_platform_orders (created_at, status) SELECT TIMESTAMP '2024-01-01' + n * INTERVAL '10 minutes', CASE WHEN 0 = n % 2 THEN 'paid' ELSE 'new' END FROM generate_series(1, 50000) AS n",
        );
        $this->connection->executeStatement('ANALYZE dd_platform_orders');

        // Index scan returning a few thousand rows, then a range matching every row (sequential scan chosen over the index).
        self::assertSame([], $this->missingIndexTitles("SELECT * FROM dd_platform_orders WHERE created_at >= '2024-01-10' AND created_at < '2024-02-10'"));
        self::assertSame([], $this->missingIndexTitles("SELECT * FROM dd_platform_orders WHERE created_at >= '2024-01-01' AND created_at < '2026-01-01'"));
        self::assertContains('Missing Index Detected', $this->missingIndexTitles("SELECT * FROM dd_platform_orders WHERE status = 'paid'"));
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
}
