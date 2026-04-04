<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

class PaginatorQueryDetector
{
    /**
     * @param array<int, array<string, mixed>>|null $backtrace
     */
    public static function isPaginatorQuery(?array $backtrace): bool
    {
        if (null === $backtrace) {
            return false;
        }

        foreach ($backtrace as $frame) {
            $class = $frame['class'] ?? '';
            if (str_contains($class, 'Pagination\Paginator') || str_contains($class, 'EntityPaginator')) {
                return true;
            }
        }

        return false;
    }
}
