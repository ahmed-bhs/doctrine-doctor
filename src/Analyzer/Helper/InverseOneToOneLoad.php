<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The inverse side of a OneToOne is loaded with its owner, because Doctrine cannot make it lazy. Its query has the
 * shape of a collection load (SELECT ... FROM owning_table WHERE fk = ?): only the persister call tells them apart.
 */
final class InverseOneToOneLoad
{
    /**
     * @param array<int, array<string, mixed>>|null $backtrace
     */
    public static function inBacktrace(?array $backtrace): bool
    {
        return array_any($backtrace ?? [], fn ($frame) => 'loadOneToOneEntity' === ($frame['function'] ?? null));
    }

    /**
     * The inverse field loaded from the owning table by its foreign key column, as the suggestion template context.
     *
     * @return array{entity_class: string, entity_fqcn: string, field_name: string, target_class: string, target_fqcn: string, mapped_by: string}|null
     */
    public static function resolve(EntityManagerInterface $entityManager, string $owningTable, string $foreignKeyColumn): ?array
    {
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $owning) {
            if ($owning->getTableName() !== $owningTable) {
                continue;
            }

            foreach ($owning->getAssociationMappings() as $owningField => $mapping) {
                if (!self::joinsOn($mapping, $foreignKeyColumn)) {
                    continue;
                }
                $inverseField = MappingHelper::getString($mapping, 'inversedBy');
                $inverseClass = MappingHelper::getString($mapping, 'targetEntity');
                if (null === $inverseField || null === $inverseClass) {
                    return null;
                }

                return [
                    'entity_class' => self::shortName($inverseClass),
                    'entity_fqcn' => $inverseClass,
                    'field_name' => $inverseField,
                    'target_class' => self::shortName($owning->getName()),
                    'target_fqcn' => $owning->getName(),
                    'mapped_by' => (string) $owningField,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private static function joinsOn(array|object $mapping, string $column): bool
    {
        return array_any(MappingHelper::getArray($mapping, 'joinColumns') ?? [], fn ($joinColumn) => (\is_array($joinColumn) || \is_object($joinColumn)) && 0 === strcasecmp((string) MappingHelper::getString($joinColumn, 'name'), $column));
    }

    private static function shortName(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }
}
