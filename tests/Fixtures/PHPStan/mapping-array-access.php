<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\PHPStan;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\JoinColumnMapping;

/**
 * @param ClassMetadata<object> $metadata
 * @param array<string, mixed>|AssociationMapping $mappingOrArray
 */
function mappingArrayAccess(
    AssociationMapping $association,
    FieldMapping $field,
    JoinColumnMapping $joinColumn,
    ClassMetadata $metadata,
    array|AssociationMapping $mappingOrArray,
): void {
    $association['targetEntity'];
    $field['nullable'];
    $joinColumn['onDelete'];

    isset($association['mappedBy']);
    $field['type'] ?? null;

    foreach ($metadata->getAssociationMappings() as $mapping) {
        $mapping['type'];
    }

    // Allowed: property access, and arrays narrowed out of a union
    $association->targetEntity;
    $field->nullable;

    if (is_array($mappingOrArray)) {
        $mappingOrArray['targetEntity'];
    }

    $plainArray = ['type' => 'string'];
    $plainArray['type'];
}
