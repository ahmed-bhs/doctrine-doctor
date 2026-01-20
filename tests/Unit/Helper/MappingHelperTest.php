<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Helper;

use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use PHPUnit\Framework\TestCase;

final class MappingHelperTest extends TestCase
{
    public function test_get_property_from_array(): void
    {
        $mapping = [
            'type' => 'string',
            'fieldName' => 'name',
            'columnName' => 'name',
        ];

        self::assertSame('string', MappingHelper::getProperty($mapping, 'type'));
        self::assertSame('name', MappingHelper::getProperty($mapping, 'fieldName'));
        self::assertNull(MappingHelper::getProperty($mapping, 'nonexistent'));
    }

    public function test_get_property_from_object(): void
    {
        $mapping = new class() {
            public string $type = 'integer';

            public string $fieldName = 'id';

            public string $columnName = 'id';
        };

        self::assertSame('integer', MappingHelper::getProperty($mapping, 'type'));
        self::assertSame('id', MappingHelper::getProperty($mapping, 'fieldName'));
        self::assertNull(MappingHelper::getProperty($mapping, 'nonexistent'));
    }

    public function test_has_property_from_array(): void
    {
        $mapping = [
            'type' => 'string',
            'fieldName' => 'name',
        ];

        self::assertTrue(MappingHelper::hasProperty($mapping, 'type'));
        self::assertTrue(MappingHelper::hasProperty($mapping, 'fieldName'));
        self::assertFalse(MappingHelper::hasProperty($mapping, 'nonexistent'));
    }

    public function test_has_property_from_object(): void
    {
        $mapping = new class() {
            public string $type = 'integer';

            public string $fieldName = 'id';

            public ?string $nullable = null;
        };

        self::assertTrue(MappingHelper::hasProperty($mapping, 'type'));
        self::assertTrue(MappingHelper::hasProperty($mapping, 'fieldName'));
        self::assertFalse(MappingHelper::hasProperty($mapping, 'nullable')); // null value
        self::assertFalse(MappingHelper::hasProperty($mapping, 'nonexistent'));
    }
}
