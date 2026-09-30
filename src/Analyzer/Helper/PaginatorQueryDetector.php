<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

class PaginatorQueryDetector
{
    public function hasOrderedPaginatorSubquery(string $sql): bool
    {
        $statement = new Parser($sql)->statements[0] ?? null;
        if (!$statement instanceof SelectStatement || 1 !== count($statement->from) || !empty($statement->join)) {
            return false;
        }

        $source = $statement->from[0];
        // LimitSubqueryOutputWalker uses this wrapper for its identifier query.
        // An ORDER BY in a literal, comment or unrelated subquery is not sufficient.
        if ('dctrn_result' !== $source->alias || null === $source->expr) {
            return false;
        }

        $subquery = trim($source->expr);
        if (!str_starts_with($subquery, '(') || !str_ends_with($subquery, ')')) {
            return false;
        }

        $inner = new Parser(substr($subquery, 1, -1))->statements[0] ?? null;

        return $inner instanceof SelectStatement && null !== $inner->order && [] !== $inner->order;
    }

    /**
     * @param array<int, array<string, mixed>>|null $backtrace
     */
    public function isPaginatorQuery(?array $backtrace): bool
    {
        if (null === $backtrace) {
            return false;
        }

        foreach ($backtrace as $frame) {
            $class = $frame['class'] ?? '';
            // Any class of the ORM pagination namespace: Paginator, and the
            // OffsetPaginator / CursorPaginator that replace it since ORM 3.7.
            if (str_contains($class, 'Tools\Pagination\\') || str_contains($class, 'Pagination\Paginator') || str_contains($class, 'EntityPaginator')) {
                return true;
            }
        }

        return false;
    }
}
