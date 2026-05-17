<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DTO;

use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryDataRedactionTest extends TestCase
{
    #[Test]
    public function it_redacts_password_param(): void
    {
        $queryData = new QueryData(
            sql: 'INSERT INTO users (username, password) VALUES (?, ?)',
            executionTime: QueryExecutionTime::fromMilliseconds(1.0),
            params: ['username' => 'admin', 'password' => 'plaintext-secret'],
        );

        $array = $queryData->toArray();

        self::assertSame('[REDACTED]', $array['params']['password']);
        self::assertSame('admin', $array['params']['username']);
    }

    #[Test]
    public function it_redacts_token_and_secret_keys(): void
    {
        $queryData = new QueryData(
            sql: 'UPDATE sessions SET access_token = ?, csrf_token = ? WHERE user_id = ?',
            executionTime: QueryExecutionTime::fromMilliseconds(1.0),
            params: ['access_token' => 'abc', 'csrf_token' => 'xyz', 'user_id' => 42],
        );

        $array = $queryData->toArray();

        self::assertSame('[REDACTED]', $array['params']['access_token']);
        self::assertSame('[REDACTED]', $array['params']['csrf_token']);
        self::assertSame(42, $array['params']['user_id']);
    }

    #[Test]
    public function it_redacts_nested_sensitive_keys(): void
    {
        $queryData = new QueryData(
            sql: 'UPDATE config SET data = ?',
            executionTime: QueryExecutionTime::fromMilliseconds(1.0),
            params: ['data' => ['name' => 'test', 'api_key' => 'leaked']],
        );

        $array = $queryData->toArray();

        self::assertSame('[REDACTED]', $array['params']['data']['api_key']);
        self::assertSame('test', $array['params']['data']['name']);
    }
}
