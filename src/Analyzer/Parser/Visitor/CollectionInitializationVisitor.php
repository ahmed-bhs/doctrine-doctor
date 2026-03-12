<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\InitializationPattern\EmptyArrayAssignmentPattern;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\InitializationPattern\InitializationPatternInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\InitializationPattern\NewCollectionAssignmentPattern;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor\InitializationPattern\PromotedPropertyPattern;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class CollectionInitializationVisitor extends NodeVisitorAbstract
{
    private bool $hasInitialization = false;

    /** @var list<InitializationPatternInterface> */
    private readonly array $patterns;

    /**
     * @param list<InitializationPatternInterface>|null $patterns
     */
    public function __construct(
        private readonly string $fieldName,
        ?array $patterns = null,
    ) {
        $this->patterns = $patterns ?? self::defaultPatterns();
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->hasInitialization) {
            return null;
        }

        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($node, $this->fieldName)) {
                $this->hasInitialization = true;

                return null;
            }
        }

        return null;
    }

    public function hasInitialization(): bool
    {
        return $this->hasInitialization;
    }

    /**
     * @return list<InitializationPatternInterface>
     */
    private static function defaultPatterns(): array
    {
        return [
            new NewCollectionAssignmentPattern(),
            new EmptyArrayAssignmentPattern(),
            new PromotedPropertyPattern(),
        ];
    }
}
