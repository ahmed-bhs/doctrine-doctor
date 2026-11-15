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
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Application classes that receive the EntityManager in their constructor. Business code then depends on the
 * persistence layer instead of an abstraction it owns (dependency inversion). Repositories, Doctrine listeners,
 * fixtures, migrations and classes of an infrastructure or persistence namespace are left out.
 */
class EntityManagerInServiceAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    private const array ENTITY_MANAGER_TYPES = [
        \Doctrine\ORM\EntityManagerInterface::class,
        \Doctrine\ORM\EntityManager::class,
        \Doctrine\ORM\Decorator\EntityManagerDecorator::class,
        \Doctrine\Persistence\ObjectManager::class,
    ];

    private const array DOCTRINE_LISTENER_MARKERS = [
        \Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener::class,
        \Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener::class,
        \Doctrine\Common\EventSubscriber::class,
        'Doctrine\\Bundle\\DoctrineBundle\\EventSubscriber\\EventSubscriberInterface',
    ];

    public function __construct(
        private readonly ProjectSourceScanner $projectSourceScanner,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
        /** @var list<string> namespace segments of persistence code, allowed to use the EntityManager */
        private readonly array $excludedNamespaces = ['Repository', 'Repositories', 'Infrastructure', 'Persistence', 'Doctrine', 'DataFixtures', 'Migration', 'Migrations'],
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            foreach ($this->projectSourceScanner->classes() as ['file' => $file, 'class' => $class]) {
                if ($this->isExcluded($class)) {
                    continue;
                }

                $parameter = $this->entityManagerParameter($class);
                if (null !== $parameter) {
                    yield $this->createIssue($class, $file, $parameter);
                }
            }
        });
    }

    public function getName(): string
    {
        return 'EntityManager In Service Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects application services that receive the EntityManager instead of a repository or an interface';
    }

    private function isExcluded(Node\Stmt\Class_ $class): bool
    {
        $name = (string) $class->namespacedName;
        if ($class->isAbstract() || [] !== array_intersect(explode('\\', $name), $this->excludedNamespaces)) {
            return true;
        }

        $parent = $class->extends?->toString() ?? '';
        if (str_ends_with($parent, 'Repository')) {
            return true;
        }

        $markers = array_map(static fn (Node\Name $name): string => $name->toString(), $class->implements);
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $markers[] = $attribute->name->toString();
            }
        }

        return [] !== array_intersect($markers, self::DOCTRINE_LISTENER_MARKERS);
    }

    private function entityManagerParameter(Node\Stmt\Class_ $class): ?string
    {
        $constructor = $class->getMethod('__construct');
        if (null === $constructor) {
            return null;
        }

        foreach ($constructor->params as $param) {
            if ($param->type instanceof Node\Name
                && \in_array($param->type->toString(), self::ENTITY_MANAGER_TYPES, true)
                && $param->var instanceof Node\Expr\Variable
                && \is_string($param->var->name)) {
                return $param->var->name;
            }
        }

        return null;
    }

    /**
     * Entities passed to getRepository(): those repositories can be injected instead.
     *
     * @return list<string>
     */
    private function repositoriesUsed(Node\Stmt\Class_ $class): array
    {
        $entities = [];
        foreach (new NodeFinder()->findInstanceOf($class->stmts, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier
                && 'getRepository' === $call->name->toString()
                && isset($call->args[0])
                && $call->args[0] instanceof Node\Arg
                && $call->args[0]->value instanceof Node\Expr\ClassConstFetch
                && $call->args[0]->value->class instanceof Node\Name) {
                $entities[] = $this->shortName($call->args[0]->value->class->toString());
            }
        }

        $entities = array_values(array_unique($entities));
        sort($entities);

        return $entities;
    }

    /**
     * Methods called on $this->{parameter}, the injected EntityManager.
     *
     * @return list<string>
     */
    private function entityManagerCalls(Node\Stmt\Class_ $class, string $parameter): array
    {
        $calls = [];
        foreach (new NodeFinder()->findInstanceOf($class->stmts, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier
                && $call->var instanceof Node\Expr\PropertyFetch
                && $call->var->var instanceof Node\Expr\Variable
                && 'this' === $call->var->var->name
                && $call->var->name instanceof Node\Identifier
                && $parameter === $call->var->name->toString()) {
                $calls[] = $call->name->toString();
            }
        }

        $calls = array_values(array_unique($calls));
        sort($calls);

        return $calls;
    }

    private function createIssue(Node\Stmt\Class_ $class, string $file, string $parameter): IntegrityIssue
    {
        $fqcn = (string) $class->namespacedName;
        $shortName = $this->shortName($fqcn);
        $repositories = $this->repositoriesUsed($class);
        $otherCalls = array_values(array_diff($this->entityManagerCalls($class, $parameter), ['getRepository']));

        $consequence = ' Its logic depends on Doctrine directly and can only be tested with a database or a mock of the EntityManager.';
        $description = match (true) {
            [] !== $repositories && [] === $otherCalls => '{class} receives the EntityManager in its constructor and only uses it to reach {repositories}.',
            [] !== $repositories => '{class} receives the EntityManager in its constructor, calls {calls} on it and reaches {repositories}.',
            [] !== $otherCalls => '{class} receives the EntityManager in its constructor and calls {calls} on it.',
            default => '{class} receives the EntityManager in its constructor.',
        } . $consequence;

        $issueData = new IssueData(
            type: IssueType::ENTITY_MANAGER_IN_SERVICE->value,
            title: sprintf('EntityManager Injected in Service: %s', $shortName),
            description: DescriptionHighlighter::highlight($description, [
                'class' => $shortName,
                'repositories' => implode(', ', array_map(static fn (string $entity): string => $entity . 'Repository', $repositories)),
                'calls' => implode(', ', array_map(static fn (string $call): string => $call . '()', $otherCalls)),
            ]),
            severity: Severity::info(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/entity_manager_in_service',
                context: [
                    'class_name' => $shortName,
                    'parameter' => $parameter,
                    'repositories' => $repositories,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::info(),
                    title: 'Depend on a repository, not on the EntityManager',
                    tags: ['architecture', 'dependency-inversion', 'testability'],
                ),
            ),
            queries: [],
            backtrace: [['file' => $file, 'line' => $class->getStartLine(), 'class' => $fqcn, 'function' => '__construct', 'type' => '->']],
        );

        /** @var IntegrityIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + [
            'class' => $fqcn,
            'method' => '__construct',
            'file' => $file,
            'line' => $class->getStartLine(),
        ]);

        return $issue;
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return false === $position ? $fqcn : substr($fqcn, $position + 1);
    }
}
