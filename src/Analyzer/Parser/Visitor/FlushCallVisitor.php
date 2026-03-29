<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitorAbstract;

final class FlushCallVisitor extends NodeVisitorAbstract
{
    private bool $hasFlushCall = false;

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && 'flush' === $node->name->toString()
        ) {
            $this->hasFlushCall = true;
        }

        return null;
    }

    public function hasFlushCall(): bool
    {
        return $this->hasFlushCall;
    }
}
