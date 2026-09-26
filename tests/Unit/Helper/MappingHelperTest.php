<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
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

    public function test_get_association_type_from_orm3_mapping_objects(): void
    {
        // ORM 3 exposes the type through type(): owning and inverse sides are
        // distinct classes, so class-name matching on "ManyToManyAssociation" misses them.
        $cases = [
            ClassMetadata::MANY_TO_ONE => new ManyToOneAssociationMapping('author', \stdClass::class, \stdClass::class),
            ClassMetadata::ONE_TO_MANY => new OneToManyAssociationMapping('posts', \stdClass::class, \stdClass::class),
            ClassMetadata::MANY_TO_MANY => new ManyToManyOwningSideMapping('tags', \stdClass::class, \stdClass::class),
            ClassMetadata::ONE_TO_ONE => new OneToOneOwningSideMapping('profile', \stdClass::class, \stdClass::class),
        ];

        foreach ($cases as $expected => $mapping) {
            self::assertSame($expected, MappingHelper::getAssociationType($mapping), $mapping::class);
        }

        self::assertSame(ClassMetadata::MANY_TO_MANY, MappingHelper::getAssociationType(new ManyToManyInverseSideMapping('posts', \stdClass::class, \stdClass::class)));
        self::assertSame(ClassMetadata::ONE_TO_ONE, MappingHelper::getAssociationType(new OneToOneInverseSideMapping('user', \stdClass::class, \stdClass::class)));
    }

    public function test_get_association_type_from_array(): void
    {
        self::assertSame(ClassMetadata::ONE_TO_MANY, MappingHelper::getAssociationType(['type' => ClassMetadata::ONE_TO_MANY]));
        self::assertNull(MappingHelper::getAssociationType(['fieldName' => 'posts']));
    }

    public function test_get_association_type_returns_null_for_other_objects(): void
    {
        self::assertNull(MappingHelper::getAssociationType(new \stdClass()));
    }
}
