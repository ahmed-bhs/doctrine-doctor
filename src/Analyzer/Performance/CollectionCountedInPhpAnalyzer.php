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
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * count($customer->getOrders()), $customer->getOrders()->count() or ->isEmpty() on a to-many collection that is not
 * EXTRA_LAZY: Doctrine loads every element of the collection to answer with a number.
 */
class CollectionCountedInPhpAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    private const array COUNTING_METHODS = ['count', 'isEmpty'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectSourceScanner $projectSourceScanner,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            $getters = $this->lazyCollectionGetters();
            if ([] === $getters) {
                return;
            }

            $finder = new NodeFinder();
            foreach ($this->projectSourceScanner->classes() as ['file' => $file, 'class' => $class]) {
                foreach ($class->getMethods() as $method) {
                    foreach ($finder->find((array) $method->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\FuncCall) as $call) {
                        $getter = $this->countedGetter($call);
                        if (null !== $getter && isset($getters[strtolower($getter)])) {
                            yield $this->createIssue($class, $method, $call, $file, $getter, $getters[strtolower($getter)]);
                        }
                    }
                }
            }
        });
    }

    public function getName(): string
    {
        return 'Collection Counted In PHP Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects to-many collections loaded entirely to be counted, where EXTRA_LAZY or a COUNT query would do';
    }

    /**
     * Getters of to-many associations that are not EXTRA_LAZY.
     *
     * @return array<string, string> getter name (lowercase) => "Entity::$field"
     */
    private function lazyCollectionGetters(): array
    {
        $getters = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->associationMappings as $field => $mapping) {
                $type = MappingHelper::getAssociationType($mapping);
                $fetch = MappingHelper::getProperty($mapping, 'fetch');
                if (\in_array($type, [ClassMetadata::ONE_TO_MANY, ClassMetadata::MANY_TO_MANY], true) && ClassMetadata::FETCH_EXTRA_LAZY !== $fetch) {
                    $getters['get' . strtolower((string) $field)] ??= $metadata->getReflectionClass()->getShortName() . '::$' . $field;
                }
            }
        }

        return $getters;
    }

    /**
     * The getter in count($x->getItems()), $x->getItems()->count() or ->isEmpty(), or null.
     */
    private function countedGetter(Node $call): ?string
    {
        if ($call instanceof Node\Expr\FuncCall) {
            $argument = $call->args[0] ?? null;

            return $call->name instanceof Node\Name && 'count' === strtolower($call->name->toString())
                && $argument instanceof Node\Arg ? $this->getterName($argument->value) : null;
        }

        return $call instanceof Node\Expr\MethodCall && $call->name instanceof Node\Identifier
            && \in_array($call->name->toString(), self::COUNTING_METHODS, true) ? $this->getterName($call->var) : null;
    }

    private function getterName(Node\Expr $expr): ?string
    {
        if (($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall)
            && $expr->name instanceof Node\Identifier && [] === $expr->args && str_starts_with($expr->name->toString(), 'get')) {
            return $expr->name->toString();
        }

        return null;
    }

    private function createIssue(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method, Node $call, string $file, string $getter, string $association): PerformanceIssue
    {
        $shortName = substr((string) strrchr('\\' . $class->namespacedName, '\\'), 1);
        $methodName = $method->name->toString();

        $issueData = new IssueData(
            type: IssueType::COLLECTION_COUNTED_IN_PHP->value,
            title: sprintf('Collection Counted in PHP: %s::%s()', $shortName, $methodName),
            description: DescriptionHighlighter::highlight(
                '{method}() counts {getter}(): the whole {association} collection is loaded to return a number. '
                . 'Marked EXTRA_LAZY, the collection answers count() with a COUNT query; a list of counts is one GROUP BY query.',
                ['method' => $shortName . '::' . $methodName, 'getter' => $getter, 'association' => $association],
            ),
            severity: Severity::warning(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Performance/collection_counted_in_php',
                context: ['getter' => $getter, 'association' => $association],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::warning(),
                    title: 'Count in SQL, not by loading the collection',
                    tags: ['collection', 'extra-lazy', 'count'],
                ),
            ),
            queries: [],
        );

        /** @var PerformanceIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + ['file' => $file, 'line' => $call->getStartLine()]);

        return $issue;
    }
}
