<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\InitializationPattern;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use PhpParser\Node;
use PhpParser\Node\Name;

trait CollectionClassTrait
{
    private const array COLLECTION_CLASSES = [
        'ArrayCollection',
        'Collection',
        ArrayCollection::class,
        Collection::class,
    ];

    private function isCollectionClass(string $className): bool
    {
        $normalizedName = ltrim($className, '\\');

        foreach (self::COLLECTION_CLASSES as $collectionClass) {
            if ($normalizedName === $collectionClass) {
                return true;
            }

            $lastBackslash = strrchr($collectionClass, '\\');
            $shortName = false !== $lastBackslash ? substr($lastBackslash, 1) : $collectionClass;
            if ($normalizedName === $shortName) {
                return true;
            }
        }

        return false;
    }

    private function getClassName(Node $classNode): string
    {
        if ($classNode instanceof Name) {
            return $classNode->toString();
        }

        return '';
    }
}
