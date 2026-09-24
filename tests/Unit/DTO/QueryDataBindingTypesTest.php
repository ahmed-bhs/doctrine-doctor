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
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DoctrineDataCollector records binding types as ParameterType cases, a pure
 * enum that json_encode() cannot serialize. Issues carry QueryData into the
 * profiler's JSON export, so the types are kept as plain values.
 */
final class QueryDataBindingTypesTest extends TestCase
{
    #[Test]
    public function it_keeps_binding_types_as_json_serializable_values(): void
    {
        $queryData = QueryData::fromArray([
            'sql'   => 'SELECT * FROM users WHERE email = ? AND id = ?',
            'types' => [ParameterType::STRING, ParameterType::INTEGER],
        ]);

        self::assertSame(['STRING', 'INTEGER'], $queryData->types);
        self::assertJson(json_encode([$queryData, $queryData->toArray()], \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_keeps_dbal_3_integer_binding_types(): void
    {
        self::assertSame([\PDO::PARAM_INT], QueryData::fromArray(['sql' => 'SELECT 1', 'types' => [\PDO::PARAM_INT]])->types);
    }
}
