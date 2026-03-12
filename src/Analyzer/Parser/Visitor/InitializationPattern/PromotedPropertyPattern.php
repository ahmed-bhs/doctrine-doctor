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
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;

final class PromotedPropertyPattern implements InitializationPatternInterface
{
    use CollectionClassTrait;

    private const int VISIBILITY_FLAGS = Class_::MODIFIER_PUBLIC | Class_::MODIFIER_PROTECTED | Class_::MODIFIER_PRIVATE;

    public function matches(Node $node, string $fieldName): bool
    {
        if (!$node instanceof Param) {
            return false;
        }

        if (0 === ($node->flags & self::VISIBILITY_FLAGS)) {
            return false;
        }

        if (!$node->var instanceof Variable || $node->var->name !== $fieldName) {
            return false;
        }

        if (null === $node->default) {
            return false;
        }

        if ($node->default instanceof New_) {
            return $this->isCollectionClass($this->getClassName($node->default->class));
        }

        return $node->default instanceof Array_ && 0 === count($node->default->items);
    }
}
