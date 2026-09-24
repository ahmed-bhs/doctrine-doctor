<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Doctrine ORM 4 drops ArrayAccess from its mapping objects. ORM 3 only
 * deprecates it on some of them (FieldMapping, JoinColumnMapping...) and not
 * at all on AssociationMapping, so `$associationMapping['targetEntity']` works
 * silently today and becomes a fatal error on ORM 4.
 *
 * @implements Rule<ArrayDimFetch>
 */
class NoMappingArrayAccessRule implements Rule
{
    private const array MAPPING_CLASSES = [
        \Doctrine\ORM\Mapping\AssociationMapping::class,
        \Doctrine\ORM\Mapping\FieldMapping::class,
        \Doctrine\ORM\Mapping\JoinColumnMapping::class,
        \Doctrine\ORM\Mapping\JoinTableMapping::class,
        \Doctrine\ORM\Mapping\DiscriminatorColumnMapping::class,
        \Doctrine\ORM\Mapping\EmbeddedClassMapping::class,
    ];

    public function getNodeType(): string
    {
        return ArrayDimFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        foreach ($scope->getType($node->var)->getObjectClassReflections() as $classReflection) {
            foreach (self::MAPPING_CLASSES as $mappingClass) {
                if ($classReflection->is($mappingClass)) {
                    return [
                        RuleErrorBuilder::message(sprintf(
                            'Array access on %s is removed in Doctrine ORM 4: use its properties or MappingHelper.',
                            $mappingClass,
                        ))->identifier('doctrineDoctor.mappingArrayAccess')->build(),
                    ];
                }
            }
        }

        return [];
    }
}
