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
}
