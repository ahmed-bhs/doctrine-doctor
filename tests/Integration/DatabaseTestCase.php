<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\FixtureInterface;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests with real database connections.
 *
 * This class provides:
 * - Real database connections (SQLite in-memory by default)
 * - Schema creation from real entities
 * - Fixture loading with realistic data
 * - Query logging for analyzing database behavior
 * - Automatic cleanup after each test
 */
abstract class DatabaseTestCase extends TestCase
{
    protected EntityManagerInterface $entityManager;

    protected Connection $connection;

    protected SimpleQueryLogger $queryLogger;

    /**
     * Set up the test environment with a real database.
     */
    protected function setUp(): void
    {
        $this->queryLogger = new SimpleQueryLogger();
        $this->connection = $this->createConnection();
        $this->entityManager = $this->createEntityManager();
    }

    /**
     * Clean up after the test.
     */
    protected function tearDown(): void
    {
        $this->dropSchema();
        $this->entityManager->close();
    }

    /**
     * Create a real database connection (SQLite in-memory by default).
     * Override this method to use a different database for specific tests.
     */
    protected function createConnection(): Connection
    {
        $configuration = new Configuration();

        // Add query logging middleware
        $configuration->setMiddlewares([
            new QueryLoggingMiddleware($this->queryLogger),
        ]);

        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $configuration);
    }

    /**
     * Create a real EntityManager with the test connection.
     */
    protected function createEntityManager(): EntityManagerInterface
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Fixtures/Entity'],
            isDevMode: true,
        );
        if (method_exists($configuration, 'enableNativeLazyObjects')) {
            $configuration->enableNativeLazyObjects(true);
        }

        return new EntityManager($this->connection, $configuration);
    }

    /**
     * Create database schema from entity classes.
     *
     * @param array<class-string> $entities Array of entity class names
     */
    protected function createSchema(array $entities): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            $entities,
        );

        $schemaTool->createSchema(array_values($metadata));
    }

    /**
     * Drop all database tables.
     */
    protected function dropSchema(): void
    {
        try {
            $schemaTool = new SchemaTool($this->entityManager);
            $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

            if ([] !== $metadata) {
                $schemaTool->dropSchema($metadata);
            }
        } catch (\Exception) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Load fixtures into the database.
     *
     * @param array<FixtureInterface> $fixtures
     */
    protected function loadFixtures(array $fixtures): void
    {
        foreach ($fixtures as $fixture) {
            $fixture->load($this->entityManager);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * Start collecting database queries.
     * Queries are automatically intercepted via DBAL Middleware.
     */
    protected function startQueryCollection(): void
    {
        $this->queryLogger->reset();
        $this->queryLogger->start();
    }

    /**
     * Stop collecting queries and return them.
     * Returns all queries intercepted by the middleware.
     */
    protected function stopQueryCollection(): QueryDataCollection
    {
        $this->queryLogger->stop();
        return $this->queryLogger->getQueries();
    }

    /**
     * Manually log a query (to be used in tests).
     * Call this after database operations you want to track.
     */
    protected function logManualQuery(string $sql): void
    {
        $this->queryLogger->logQuery($sql, null, 0.001);
    }

    /**
     * Get the query logger for manual inspection.
     */
    protected function getQueryLogger(): SimpleQueryLogger
    {
        return $this->queryLogger;
    }

    /**
     * Assert that a specific number of queries were executed.
     */
    protected function assertQueryCount(int $expected, string $message = ''): void
    {
        $actual = $this->queryLogger->count();
        $message = $message ?: sprintf('Expected %d queries, but %d were executed', $expected, $actual);

        self::assertSame($expected, $actual, $message);
    }

    /**
     * Assert that queries contain a specific SQL pattern.
     */
    protected function assertQueryContains(string $pattern, string $message = ''): void
    {
        $found = array_any($this->queryLogger->getRawQueries(), fn ($query) => str_contains(strtolower((string) $query['sql']), strtolower($pattern)));
        $message = $message ?: 'No query found containing pattern: ' . $pattern;
        self::assertTrue($found, $message);
    }
}
