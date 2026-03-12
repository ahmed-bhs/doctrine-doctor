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
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\New_;

final class NewCollectionAssignmentPattern implements InitializationPatternInterface
{
    use CollectionClassTrait;
    use ThisPropertyAccessTrait;

    public function matches(Node $node, string $fieldName): bool
    {
        if (!$node instanceof Assign) {
            return false;
        }

        if (!$this->isThisPropertyAccess($node->var, $fieldName)) {
            return false;
        }

        if (!$node->expr instanceof New_) {
            return false;
        }

        return $this->isCollectionClass($this->getClassName($node->expr->class));
    }
}
