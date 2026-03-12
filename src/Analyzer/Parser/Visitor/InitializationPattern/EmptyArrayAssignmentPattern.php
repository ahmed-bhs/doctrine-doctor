<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\InitializationPattern;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;

final class EmptyArrayAssignmentPattern implements InitializationPatternInterface
{
    use ThisPropertyAccessTrait;

    public function matches(Node $node, string $fieldName): bool
    {
        if (!$node instanceof Assign) {
            return false;
        }

        if (!$this->isThisPropertyAccess($node->var, $fieldName)) {
            return false;
        }

        return $node->expr instanceof Array_ && 0 === count($node->expr->items);
    }
}
