<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Collector;

use AhmedBhs\DoctrineDoctor\Collector\Helper\DatabaseInfoCollector;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DatabaseInfoCollectorTest extends TestCase
{
    #[Test]
    public function it_does_not_open_a_lazy_connection_to_read_its_server_version(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $databaseInfo = new DatabaseInfoCollector(new NullLogger())->collectDatabaseInfo($entityManager);

        self::assertFalse($connection->isConnected());
        self::assertSame('sqlite', $databaseInfo['driver']);
        self::assertSame('N/A', $databaseInfo['database_version']);
    }
}
