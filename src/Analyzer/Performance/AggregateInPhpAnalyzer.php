<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\ProjectSourceScanner;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * A method that loads entities (findAll(), findBy(), getResult()…) and then sums them in PHP:
 *
 *     foreach ($orders as $order) { $total += $order->getTotal(); }
 *     array_sum(array_map(fn (Order $o) => $o->getTotal(), $orders))
 *
 * Every entity is hydrated to compute one number that SUM() returns in a single row.
 */
class AggregateInPhpAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    private const array LOADING_CALLS = ['findAll', 'findBy', 'getResult', 'toIterable'];

    private const array AGGREGATE_FUNCTIONS = ['array_sum', 'max', 'min'];

    public function __construct(
        private readonly ProjectSourceScanner $projectSourceScanner,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            $finder = new NodeFinder();
            foreach ($this->projectSourceScanner->classes() as ['file' => $file, 'class' => $class]) {
                foreach ($class->getMethods() as $method) {
                    $statements = (array) $method->stmts;
                    if (!$this->loadsEntities($finder, $statements)) {
                        continue;
                    }

                    $aggregate = $this->aggregateInPhp($finder, $statements);
                    if (null !== $aggregate) {
                        yield $this->createIssue($class, $method, $file, ...$aggregate);
                    }
                }
            }
        });
    }

    public function getName(): string
    {
        return 'Aggregate In PHP Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects sums computed in PHP over loaded entities, where SUM() would return one row';
    }

    /**
     * @param array<Node> $statements
     */
    private function loadsEntities(NodeFinder $finder, array $statements): bool
    {
        return null !== $finder->findFirst($statements, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && \in_array($node->name->toString(), self::LOADING_CALLS, true));
    }

    /**
     * The getter summed and the line, for "$x += $item->getY()" in a foreach or array_sum(array_map(... ->getY() ...)).
     *
     * @param array<Node> $statements
     * @return array{0: string, 1: int}|null
     */
    private function aggregateInPhp(NodeFinder $finder, array $statements): ?array
    {
        foreach ($finder->findInstanceOf($statements, Node\Stmt\Foreach_::class) as $loop) {
            $item = $loop->valueVar instanceof Node\Expr\Variable && \is_string($loop->valueVar->name) ? $loop->valueVar->name : null;
            foreach ($finder->findInstanceOf($loop->stmts, Node\Expr\AssignOp\Plus::class) as $sum) {
                $getter = $this->getterOn($sum->expr, $item);
                if (null !== $getter) {
                    return [$getter, $sum->getStartLine()];
                }
            }
        }

        foreach ($finder->findInstanceOf($statements, Node\Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof Node\Name || !\in_array(strtolower($call->name->toString()), self::AGGREGATE_FUNCTIONS, true)) {
                continue;
            }
            $map = $finder->findFirst($call->args, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name && 'array_map' === strtolower($node->name->toString()));
            if (null === $map) {
                continue;
            }
            foreach ($finder->findInstanceOf([$map], Node\Expr\MethodCall::class) as $getterCall) {
                $getter = $this->getterOn($getterCall, null);
                if (null !== $getter) {
                    return [$getter, $call->getStartLine()];
                }
            }
        }

        return null;
    }

    /**
     * "getTotal" for $item->getTotal() (on $variable when given), or null.
     */
    private function getterOn(Node\Expr $expr, ?string $variable): ?string
    {
        if (!$expr instanceof Node\Expr\MethodCall || !$expr->name instanceof Node\Identifier || [] !== $expr->args
            || !str_starts_with($expr->name->toString(), 'get')) {
            return null;
        }

        if (null !== $variable && !($expr->var instanceof Node\Expr\Variable && $variable === $expr->var->name)) {
            return null;
        }

        return $expr->name->toString();
    }

    private function createIssue(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method, string $file, string $getter, int $line): PerformanceIssue
    {
        $shortName = substr((string) strrchr('\\' . $class->namespacedName, '\\'), 1);
        $methodName = $method->name->toString();

        $issueData = new IssueData(
            type: IssueType::AGGREGATE_IN_PHP->value,
            title: sprintf('Aggregate Computed in PHP: %s::%s()', $shortName, $methodName),
            description: DescriptionHighlighter::highlight(
                '{method}() loads entities and adds up {getter}() in PHP. Every row is hydrated to compute one number that SUM() returns in a single row.',
                ['method' => $shortName . '::' . $methodName, 'getter' => $getter],
            ),
            severity: Severity::warning(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Performance/aggregate_in_php',
                context: ['getter' => $getter],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::warning(),
                    title: 'Let the database compute the sum',
                    tags: ['aggregate', 'hydration', 'sum'],
                ),
            ),
            queries: [],
        );

        /** @var PerformanceIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + ['file' => $file, 'line' => $line]);

        return $issue;
    }
}
