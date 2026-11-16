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
 * Calls such as $order->getItems()->filter(fn ...) in the application: the whole collection is loaded to keep a
 * few elements, where matching() with a Criteria would filter in SQL on a collection that is not loaded yet.
 */
class CollectionFilteredInPhpAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectSourceScanner $projectSourceScanner,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
        /** @var list<string> collection methods that load every element to keep some of them */
        private readonly array $methods = ['filter'],
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            $getters = $this->collectionGetters();
            if ([] === $getters) {
                return;
            }

            $finder = new NodeFinder();
            foreach ($this->projectSourceScanner->classes() as ['file' => $file, 'class' => $class]) {
                foreach ($class->getMethods() as $method) {
                    foreach ($finder->findInstanceOf((array) $method->stmts, Node\Expr\MethodCall::class) as $call) {
                        $getter = $this->filteredGetter($call);
                        if (null !== $getter && isset($getters[$getter])) {
                            yield $this->createIssue($class, $method, $call, $file, $getters[$getter]);
                        }
                    }
                }
            }
        });
    }

    public function getName(): string
    {
        return 'Collection Filtered In PHP Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects to-many collections loaded entirely and filtered in PHP instead of with matching() and a Criteria';
    }

    /**
     * @return array<string, string> getter name (lowercase) => "Entity::$field"
     */
    private function collectionGetters(): array
    {
        $getters = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->associationMappings as $field => $mapping) {
                $type = MappingHelper::getAssociationType($mapping);
                if (\in_array($type, [ClassMetadata::ONE_TO_MANY, ClassMetadata::MANY_TO_MANY], true)) {
                    $getters['get' . strtolower((string) $field)] ??= $metadata->getReflectionClass()->getShortName() . '::$' . $field;
                }
            }
        }

        return $getters;
    }

    /**
     * The getter in "->getItems()->filter(...)", lowercase, or null for any other call.
     */
    private function filteredGetter(Node\Expr\MethodCall $call): ?string
    {
        if (!$call->name instanceof Node\Identifier || !\in_array($call->name->toString(), $this->methods, true)) {
            return null;
        }

        $inner = $call->var;
        if (!($inner instanceof Node\Expr\MethodCall || $inner instanceof Node\Expr\NullsafeMethodCall) || !$inner->name instanceof Node\Identifier || [] !== $inner->args) {
            return null;
        }

        return strtolower($inner->name->toString());
    }

    private function createIssue(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method, Node\Expr\MethodCall $call, string $file, string $association): PerformanceIssue
    {
        $fqcn = (string) $class->namespacedName;
        $shortName = substr((string) strrchr('\\' . $fqcn, '\\'), 1);
        $methodName = $method->name->toString();
        $getter = ($call->var instanceof Node\Expr\MethodCall || $call->var instanceof Node\Expr\NullsafeMethodCall) && $call->var->name instanceof Node\Identifier ? $call->var->name->toString() : 'getItems';

        $issueData = new IssueData(
            type: IssueType::COLLECTION_FILTERED_IN_PHP->value,
            title: sprintf('Collection Filtered in PHP: %s::%s()', $shortName, $methodName),
            description: DescriptionHighlighter::highlight(
                '{method}() calls {getter}()->{filter}(): the whole {association} collection is loaded to keep only some elements. '
                . 'On a collection that is not loaded yet, matching() with a Criteria filters in SQL and fetches only those rows.',
                ['method' => $shortName . '::' . $methodName, 'getter' => $getter, 'filter' => $call->name instanceof Node\Identifier ? $call->name->toString() : 'filter', 'association' => $association],
            ),
            severity: Severity::warning(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Performance/collection_filtered_in_php',
                context: ['getter' => $getter, 'association' => $association],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::warning(),
                    title: 'Filter the collection in SQL with matching()',
                    tags: ['criteria', 'collection', 'lazy-loading'],
                ),
            ),
            queries: [],
            backtrace: [['file' => $file, 'line' => $call->getStartLine(), 'class' => $fqcn, 'function' => $methodName, 'type' => '->']],
        );

        /** @var PerformanceIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + [
            'file' => $file,
            'line' => $call->getStartLine(),
        ]);

        return $issue;
    }
}
