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
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;

trait ThisPropertyAccessTrait
{
    private function isThisPropertyAccess(Node $node, string $fieldName): bool
    {
        if (!$node instanceof PropertyFetch) {
            return false;
        }

        if (!$node->var instanceof Variable || 'this' !== $node->var->name) {
            return false;
        }

        if (!$node->name instanceof Identifier) {
            return false;
        }

        return $node->name->toString() === $fieldName;
    }
}
