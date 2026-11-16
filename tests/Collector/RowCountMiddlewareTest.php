<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Collector;

use AhmedBhs\DoctrineDoctor\Collector\RowCount\RowCountMiddleware;
use AhmedBhs\DoctrineDoctor\Collector\RowCount\RowCountRecorder;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowCountMiddlewareTest extends TestCase
{
    #[Test]
    public function it_counts_the_rows_each_select_returns(): void
    {
        $recorder = new RowCountRecorder();
        $middleware = new RowCountMiddleware($recorder);
        $middleware->setConnectionName('shop');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], new Configuration()->setMiddlewares([$middleware]));

        $connection->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY, name TEXT)');
        for ($i = 1; $i <= 5; ++$i) {
            $connection->executeStatement('INSERT INTO product (name) VALUES (?)', ['p' . $i]);
        }

        $connection->fetchAllAssociative('SELECT * FROM product');
        $result = $connection->executeQuery('SELECT id FROM product WHERE id > ?', [3]);
        while (false !== $result->fetchAssociative()) {
        }
        $connection->fetchOne('SELECT name FROM product WHERE id = 99');

        $entries = $recorder->entries('shop');
        self::assertSame(['SELECT * FROM product', 'SELECT id FROM product WHERE id > ?', 'SELECT name FROM product WHERE id = 99'], array_map(static fn ($e): string => $e->sql, $entries));
        self::assertSame([5, 2, 0], array_map(static fn ($e): int => $e->rows, $entries));
        self::assertSame([], $recorder->entries('default'));

        $recorder->reset();
        self::assertSame([], $recorder->entries('shop'));
    }
}
