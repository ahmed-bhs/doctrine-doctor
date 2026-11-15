<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\ProjectSourceScanner;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * A failed flush() closes the EntityManager. Catching the exception and using the same EntityManager again, in the
 * catch block or in the next turn of a loop, fails later with "The EntityManager is closed", far from the cause.
 */
class EntityManagerUsedAfterFailureAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    private const array ENTITY_MANAGER_CALLS = ['persist', 'flush', 'remove', 'getRepository', 'find', 'createQueryBuilder', 'createQuery', 'merge', 'refresh'];

    public function __construct(
        private readonly ProjectSourceScanner $projectSourceScanner,
        private readonly SuggestionFactoryInterface $suggestionFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            $finder = new NodeFinder();
            $printer = new Standard();
            foreach ($this->projectSourceScanner->classes() as ['file' => $file, 'class' => $class]) {
                foreach ($class->getMethods() as $method) {
                    $statements = (array) $method->stmts;
                    if ($this->resetsTheManager($finder, $statements)) {
                        continue;
                    }

                    foreach ($this->failedFlushReuses($finder, $printer, $statements) as $line) {
                        yield $this->createIssue($class, $method, $file, $line);
                        break;
                    }
                }
            }
        });
    }

    public function getName(): string
    {
        return 'EntityManager Used After Failure Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects an EntityManager used again after a failed flush() was caught, when it is closed';
    }

    /**
     * @param array<Node> $statements
     */
    private function resetsTheManager(NodeFinder $finder, array $statements): bool
    {
        return null !== $finder->findFirst($statements, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && \in_array($node->name->toString(), ['resetManager', 'isOpen'], true));
    }

    /**
     * Lines of catch blocks that use the flushed EntityManager again, or let a loop go on with it.
     *
     * @param array<Node> $statements
     * @return list<int>
     */
    private function failedFlushReuses(NodeFinder $finder, Standard $printer, array $statements): array
    {
        $lines = [];
        $loops = $finder->find($statements, static fn (Node $node): bool => $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_);

        foreach ($finder->findInstanceOf($statements, Node\Stmt\TryCatch::class) as $try) {
            $managers = [];
            foreach ($finder->findInstanceOf($try->stmts, Node\Expr\MethodCall::class) as $call) {
                if ($call->name instanceof Node\Identifier && 'flush' === $call->name->toString()) {
                    $managers[] = $printer->prettyPrintExpr($call->var);
                }
            }
            if ([] === $managers) {
                continue;
            }

            $inLoop = [] !== array_filter($loops, static fn (Node $loop): bool => $loop->getStartLine() < $try->getStartLine() && $loop->getEndLine() > $try->getEndLine());

            foreach ($try->catches as $catch) {
                $reused = null !== $finder->findFirst($catch->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && \in_array($node->name->toString(), self::ENTITY_MANAGER_CALLS, true)
                    && \in_array($printer->prettyPrintExpr($node->var), $managers, true));
                $leaves = null !== $finder->findFirst($catch->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Throw_ || $node instanceof Node\Stmt\Return_ || $node instanceof Node\Stmt\Break_);

                if ($reused || ($inLoop && !$leaves)) {
                    $lines[] = $catch->getStartLine();
                }
            }
        }

        return $lines;
    }

    private function createIssue(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method, string $file, int $line): IntegrityIssue
    {
        $shortName = substr((string) strrchr('\\' . $class->namespacedName, '\\'), 1);
        $methodName = $method->name->toString();

        return new IntegrityIssue([
            'type' => IssueType::ENTITY_MANAGER_USED_AFTER_FAILURE->value,
            'title' => sprintf('EntityManager Used After Failure: %s::%s()', $shortName, $methodName),
            'description' => sprintf(
                '%s::%s() catches the exception of flush() and keeps using the same EntityManager. A failed flush() closes it, so the next persist() or flush() throws "The EntityManager is closed", far from the real error.',
                $shortName,
                $methodName,
            ),
            'severity' => Severity::warning(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/entity_manager_used_after_failure',
                context: ['method' => $shortName . '::' . $methodName],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::warning(),
                    title: 'Reset the EntityManager or stop after a failed flush',
                    tags: ['entity-manager', 'exception', 'transaction'],
                ),
            ),
            'queries' => [],
            'file' => $file,
            'line' => $line,
        ]);
    }
}
