<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\Helper;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Webmozart\Assert\Assert;

/**
 * Helper for collecting database information.
 * Extracted from DoctrineDoctorDataCollector to reduce complexity.
 */
final readonly class DatabaseInfoCollector
{
    public function __construct(
        private LoggerInterface $logger,
        private DataCollectorConfig $dataCollectorConfig = new DataCollectorConfig(),
    ) {
    }

    /**
     * Collect database info (heavy I/O - called lazily).
     * @return array<string, mixed>
     */
    public function collectDatabaseInfo(?EntityManagerInterface $entityManager): array
    {
        $info = $this->getDefaultDatabaseInfo();

        try {
            $doctrineVersion = $this->detectDoctrineVersion();

            if (null !== $doctrineVersion) {
                $info['doctrine_version'] = $doctrineVersion;
                $this->checkVersionDeprecation($info, $doctrineVersion);
            }

            $this->collectDatabasePlatformInfo($entityManager, $info);
        } catch (\Throwable $throwable) {
            $this->logErrorIfDebugEnabled('Failed to collect database info', $throwable);
        }

        return $info;
    }

    /**
     * Get default database info structure.
     */
    public function getDefaultDatabaseInfo(): array
    {
        return [
            'driver'              => 'N/A',
            'database_version'    => 'N/A',
            'doctrine_version'    => 'N/A',
            'is_deprecated'       => false,
            'deprecation_message' => null,
        ];
    }

    /**
     * Detect Doctrine ORM version using multiple methods.
     */
    private function detectDoctrineVersion(): ?string
    {
        // Method 1: Using Composer lock file (most reliable for Doctrine 3.x)
        $doctrineVersion = $this->getVersionFromComposerLock();

        // Method 2: Using Doctrine\ORM\Version class (for Doctrine 2.x)
        if (null === $doctrineVersion && class_exists('Doctrine\ORM\Version')) {
            return $this->getVersionFromReflection();
        }

        return $doctrineVersion;
    }

    /**
     * Get Doctrine version from composer.lock file.
     */
    private function getVersionFromComposerLock(): ?string
    {
        try {
            $possiblePaths = [
                __DIR__ . '/../../../composer.lock', // Inside the bundle itself
                dirname(__DIR__, 6) . '/composer.lock', // Project root
            ];

            Assert::isIterable($possiblePaths, '$possiblePaths must be iterable');

            foreach ($possiblePaths as $possiblePath) {
                if (file_exists($possiblePath)) {
                    $contents = file_get_contents($possiblePath);
                    if (false === $contents) {
                        continue;
                    }

                    $composerLock = json_decode($contents, true);

                    if (is_array($composerLock)) {
                        foreach ($composerLock['packages'] ?? [] as $package) {
                            if ('doctrine/orm' === $package['name']) {
                                return $package['version'];
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $this->logDebugIfEnabled('Failed to get Doctrine version from composer.lock', $throwable);
        }

        return null;
    }

    /**
     * Get Doctrine version from Version class reflection.
     */
    private function getVersionFromReflection(): ?string
    {
        try {
            if (!class_exists(\Doctrine\ORM\Version::class)) {
                return null;
            }

            $reflectionClass = new ReflectionClass(\Doctrine\ORM\Version::class);
            $constant        = $reflectionClass->getConstant('VERSION');

            if (false !== $constant) {
                return (string) $constant;
            }
        } catch (\Throwable $throwable) {
            $this->logDebugIfEnabled('Failed to get Doctrine version from reflection', $throwable);
        }

        return null;
    }

    /**
     * Check if Doctrine version is deprecated and update info.
     * @param array<string, mixed> $info
     */
    private function checkVersionDeprecation(array &$info, string $doctrineVersion): void
    {
        $cleanVersion = preg_replace('/^v/', '', $doctrineVersion);
        $versionParts = explode('.', (string) $cleanVersion);
        $majorVersion = (int) ($versionParts[0] ?? 0);
        $minorVersion = (int) ($versionParts[1] ?? 0);

        if ($majorVersion < 2 || (2 === $majorVersion && $minorVersion < 15)) {
            $info['is_deprecated']       = true;
            $info['deprecation_message'] = sprintf(
                'Doctrine ORM %s is deprecated. Please upgrade to version 2.15+ or 3.x',
                $doctrineVersion,
            );
        }
    }

    /**
     * Collect database platform and version information.
     * @param array<string, mixed> $info
     */
    private function collectDatabasePlatformInfo(?EntityManagerInterface $entityManager, array &$info): void
    {
        $connection = $this->getConnection($entityManager);

        if ($connection instanceof Connection) {
            $this->extractPlatformInfo($connection, $info);
        }
    }

    /**
     * Get database connection from EntityManager.
     */
    private function getConnection(?EntityManagerInterface $entityManager): ?Connection
    {
        if ($entityManager instanceof EntityManagerInterface) {
            try {
                return $entityManager->getConnection();
            } catch (\Throwable $exception) {
                $this->logDebugIfEnabled('Failed to get database connection from EntityManager', $exception);
            }
        }

        return null;
    }

    /**
     * Extract platform and version information from connection.
     * @param array<string, mixed> $info
     */
    private function extractPlatformInfo(Connection $connection, array &$info): void
    {
        try {
            $platform = $connection->getDatabasePlatform();

            $info['driver'] = match (true) {
                $platform instanceof PostgreSQLPlatform => 'postgresql',
                $platform instanceof SQLServerPlatform  => 'sqlserver',
                $platform instanceof MySQLPlatform,
                $platform instanceof MySQL80Platform => 'mysql',
                $platform instanceof MariaDBPlatform => 'mariadb',
                $platform instanceof SQLitePlatform  => 'sqlite',
                $platform instanceof OraclePlatform  => 'oracle',
                default                              => 'unknown',
            };

            $this->extractDatabaseVersion($connection, $info);
        } catch (\Throwable $throwable) {
            $this->logDebugIfEnabled('Failed to extract database platform info', $throwable);
        }
    }

    /**
     * Extract database version from connection.
     * @param array<string, mixed> $info
     */
    private function extractDatabaseVersion(Connection $connection, array &$info): void
    {
        try {
            $nativeConnection = $connection->getNativeConnection();

            if ($nativeConnection instanceof \PDO) {
                $version = $nativeConnection->getAttribute(\PDO::ATTR_SERVER_VERSION);

                if (is_string($version) && '' !== $version) {
                    $info['database_version'] = $version;
                }
            }
        } catch (\Throwable $throwable) {
            $this->logWarningIfDebugEnabled('Failed to get database version', $throwable);
        }
    }

    /**
     * Log error if debug mode is enabled.
     */
    private function logErrorIfDebugEnabled(string $message, \Throwable $throwable): void
    {
        if ($this->dataCollectorConfig->debugMode ?? false) {
            $this->logger->error('DoctrineDoctor: ' . $message, [
                'exception' => $throwable::class,
                'message'   => $throwable->getMessage(),
                'file'      => $throwable->getFile(),
                'line'      => $throwable->getLine(),
            ]);
        }
    }

    /**
     * Log warning if debug mode is enabled.
     */
    private function logWarningIfDebugEnabled(string $message, \Throwable $throwable): void
    {
        if ($this->dataCollectorConfig->debugMode ?? false) {
            $this->logger->warning('DoctrineDoctor: ' . $message, [
                'exception' => $throwable::class,
                'message'   => $throwable->getMessage(),
                'file'      => $throwable->getFile(),
                'line'      => $throwable->getLine(),
            ]);
        }
    }

    /**
     * Log debug message if debug mode is enabled.
     */
    private function logDebugIfEnabled(string $message, \Throwable $throwable): void
    {
        if ($this->dataCollectorConfig->debugMode ?? false) {
            $this->logger->debug('DoctrineDoctor: ' . $message, [
                'exception' => $throwable::class,
                'message'   => $throwable->getMessage(),
                'file'      => $throwable->getFile(),
                'line'      => $throwable->getLine(),
            ]);
        }
    }
}
