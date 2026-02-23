<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\PlatformAnalysisStrategyFactory;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionRendererInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Helper for creating real dependencies in platform-specific analyzer tests.
 * Uses real objects instead of mocks for more realistic integration tests.
 */
class PlatformAnalyzerTestHelper
{
    /**
     * Create a real SQLite in-memory connection for testing.
     * This is perfect for unit/integration tests - fast, no setup needed.
     */
    public static function createSQLiteConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    /**
     * Alias for createSQLiteConnection() - used in unit tests.
     */
    public static function createTestConnection(): Connection
    {
        return self::createSQLiteConnection();
    }

    /**
     * Create a real MySQL connection for testing.
     * Only use if MySQL is available in your environment.
     */
    public static function createMySQLConnection(): Connection
    {
        /** @var array{driver: 'pdo_mysql', host: non-falsy-string, port: non-falsy-string, user: non-falsy-string, password: string, dbname: non-falsy-string, charset: string} $params */
        $params = [
            'driver' => 'pdo_mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (getenv('DB_PORT') ?: '3306'),
            'user' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'dbname' => getenv('DB_NAME') ?: 'doctrine_doctor_test',
            'charset' => 'utf8mb4',
        ];

        return DriverManager::getConnection($params); // @phpstan-ignore-line
    }

    /**
     * Create a real PostgreSQL connection for testing.
     */
    public static function createPostgreSQLConnection(): Connection
    {
        /** @var array{driver: 'pdo_pgsql', host: non-falsy-string, port: non-falsy-string, user: non-falsy-string, password: string, dbname: non-falsy-string} $params */
        $params = [
            'driver' => 'pdo_pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (getenv('PG_PORT') ?: '5432'),
            'user' => getenv('PG_USER') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: '',
            'dbname' => getenv('PG_NAME') ?: 'doctrine_doctor_test',
        ];

        return DriverManager::getConnection($params); // @phpstan-ignore-line
    }

    /**
     * Create real IssueFactory.
     */
    public static function createIssueFactory(): IssueFactoryInterface
    {
        return new IssueFactory();
    }

    /**
     * Create real SuggestionFactory with template renderer.
     */
    public static function createSuggestionFactory(): SuggestionFactory
    {
        return new SuggestionFactory(self::createTemplateRenderer());
    }

    /**
     * Create real template renderer.
     */
    public static function createTemplateRenderer(): SuggestionRendererInterface
    {
        return new PhpTemplateRenderer();
    }

    /**
     * Create real PlatformAnalysisStrategyFactory with all dependencies.
     */
    public static function createStrategyFactory(Connection $connection): PlatformAnalysisStrategyFactory
    {
        $suggestionFactory = self::createSuggestionFactory();
        $databasePlatformDetector = new DatabasePlatformDetector($connection);

        return new PlatformAnalysisStrategyFactory(
            $connection,
            $suggestionFactory,
            $databasePlatformDetector,
        );
    }

    /**
     * Check if MySQL is available for testing.
     */
    public static function isMySQLAvailable(): bool
    {
        try {
            $connection = self::createMySQLConnection();
            $connection->connect(); // @phpstan-ignore-line method.protected
            return $connection->isConnected();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if PostgreSQL is available for testing.
     */
    public static function isPostgreSQLAvailable(): bool
    {
        try {
            $connection = self::createPostgreSQLConnection();
            $connection->connect(); // @phpstan-ignore-line method.protected
            return $connection->isConnected();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function createTestConfiguration(?array $entityPaths = null): \Doctrine\ORM\Configuration
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: $entityPaths ?? [__DIR__ . '/../Fixtures/Entity'],
            isDevMode: true,
        );
        if (method_exists($configuration, 'enableNativeLazyObjects')) {
            $configuration->enableNativeLazyObjects(true);
        }

        return $configuration;
    }

    public static function createTestEntityManager(?array $entityPaths = null): EntityManager
    {
        $connection = self::createSQLiteConnection();
        $configuration = self::createTestConfiguration($entityPaths);

        return new EntityManager($connection, $configuration);
    }

    /**
     * Create TwigTemplateRenderer with all common templates for testing.
     * Use this when tests need Twig-based template rendering.
     */
    public static function createTwigTemplateRenderer(): TwigTemplateRenderer
    {
        $templates = [
            'default' => 'Suggestion: {{ message }}',
            'orphan_removal' => 'Fix orphanRemoval in {{ entity_class }}.{{ field_name }}',
            'on_delete_cascade_mismatch' => 'Fix cascade mismatch in {{ entity_class }}.{{ field_name }}',
            'cascade_configuration' => 'Fix cascade in {{ entity_class }}.{{ field_name }}',
            'missing_orphan_removal' => 'Add orphanRemoval to {{ entity_class }}.{{ field_name }}',
            'bidirectional_consistency' => 'Fix bidirectional consistency in {{ entity_class }}.{{ field_name }}',
        ];

        $arrayLoader = new ArrayLoader($templates);
        $twigEnvironment = new Environment($arrayLoader);

        return new TwigTemplateRenderer($twigEnvironment);
    }
}
