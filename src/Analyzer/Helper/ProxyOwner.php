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

final class ProxyOwner
{
    /**
     * Which association returned the proxies: the getter called on the trigger line whose
     * association targets the lazy-loaded entity (`$item->getVariant()->getProduct()`).
     * @param array<int, array<string, mixed>>|null $backtrace
     * @return array{entity: string, field: string}|null
     */
    public static function resolve(EntityManagerInterface $entityManager, string $targetClass, ?array $backtrace): ?array
    {
        $frame = array_find($backtrace ?? [], fn ($candidate) => \is_string($candidate['file'] ?? null) && !str_contains($candidate['file'], '/vendor/'));
        $file = $frame['file'] ?? null;
        $line = $frame['line'] ?? null;
        if (!\is_string($file) || !\is_int($line) || !is_readable($file)) {
            return null;
        }

        $lines = file($file);
        if (false === $lines) {
            return null;
        }

        $fields = self::getters($lines[$line - 1] ?? '');
        if ([] === $fields) {
            return null;
        }

        // The lines just above often say where the owner comes from: foreach ($order->getItems() as $item).
        $context = self::getters(implode('', \array_slice($lines, max(0, $line - 7), 7)));

        try {
            $metadatas = $entityManager->getMetadataFactory()->getAllMetadata();
        } catch (\Throwable) {
            return null;
        }

        $found = [];
        $reached = [];
        foreach ($metadatas as $metadata) {
            foreach ($metadata->getAssociationNames() as $field) {
                $target = $metadata->getAssociationTargetClass($field);
                if (\in_array($field, $context, true)) {
                    $reached[$target] = true;
                }
                if (\in_array($field, $fields, true) && $target === $targetClass && $metadata->isSingleValuedAssociation($field)) {
                    $found[] = ['entity' => $metadata->getName(), 'field' => $field];
                }
            }
        }

        if (\count($found) > 1) {
            $found = array_values(array_filter($found, static fn (array $owner): bool => isset($reached[$owner['entity']])));
        }

        return 1 === \count($found) ? $found[0] : null;
    }

    /**
     * @return list<string> the fields read by ->getX() calls
     */
    private static function getters(string $source): array
    {
        preg_match_all('/->get(\w+)\(\)/', $source, $matches);

        return array_map(lcfirst(...), $matches[1]);
    }
}
