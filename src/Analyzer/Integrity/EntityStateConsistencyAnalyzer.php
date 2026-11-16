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
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\ProjectSourceScanner;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * A new entity attached by the application code to a required association that does not cascade persist:
 *
 *     $invoice->setCustomer(new Customer($email));
 *     $this->customer = new Customer($email);
 *
 * The new entity cannot have been persisted, so flush() fails with "A new entity was found through the relationship".
 * Required associations without cascade are the normal case (the target lives on its own): only the code that creates
 * the target inline is reported.
 */
class EntityStateConsistencyAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly ?ProjectSourceScanner $projectSourceScanner = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                $scanner = $this->projectSourceScanner;
                $associations = null === $scanner ? [] : $this->requiredAssociationsWithoutCascade();
                if (null === $scanner || [] === $associations) {
                    return;
                }

                $finder = new NodeFinder();
                foreach ($scanner->classes() as ['file' => $file, 'class' => $class]) {
                    foreach ($class->getMethods() as $method) {
                        foreach ($finder->find((array) $method->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\MethodCall) as $node) {
                            $match = $this->attachedNewEntity($node, $associations);
                            if (null !== $match) {
                                yield $this->createIssue($class, $method, $file, $node->getStartLine(), ...$match);
                            }
                        }
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Entity State Consistency Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects a new entity attached to a required association that does not cascade persist';
    }

    /**
     * @return array<string, array{owner: string, field: string}> keyed by "lowercase field|target class"
     */
    private function requiredAssociationsWithoutCascade(): array
    {
        $associations = [];
        $cascading = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $classMetadata) {
            foreach ($classMetadata->getAssociationMappings() as $field => $mapping) {
                if (!$classMetadata->isSingleValuedAssociation($field)) {
                    continue;
                }
                $key = strtolower((string) $field) . '|' . ltrim($mapping->targetEntity, '\\');
                if (\in_array('persist', $mapping->cascade, true)) {
                    $cascading[$key] = true;
                } elseif (!MappingHelper::isJoinColumnNullable($mapping)) {
                    $associations[$key] = ['owner' => $this->shortClassName($classMetadata->getName()), 'field' => (string) $field];
                }
            }
        }

        // The code does not say which entity receives setCustomer(): skip a name that also cascades elsewhere.
        return array_diff_key($associations, $cascading);
    }

    /**
     * "$this->customer = new Customer()" or "->setCustomer(new Customer())" on a known association.
     *
     * @param array<string, array{owner: string, field: string}> $associations
     * @return array{0: string, 1: string, 2: string}|null owner, field, target short name
     */
    private function attachedNewEntity(Node $node, array $associations): ?array
    {
        if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\PropertyFetch && $node->var->name instanceof Node\Identifier) {
            [$field, $value] = [$node->var->name->toString(), $node->expr];
        } elseif ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
            && str_starts_with($node->name->toString(), 'set') && ($node->args[0] ?? null) instanceof Node\Arg) {
            [$field, $value] = [substr($node->name->toString(), 3), $node->args[0]->value];
        } else {
            return null;
        }

        if (!$value instanceof Node\Expr\New_ || !$value->class instanceof Node\Name) {
            return null;
        }
        $target = $value->class->toString();
        $association = $associations[strtolower($field) . '|' . ltrim($target, '\\')] ?? null;

        return null === $association ? null : [$association['owner'], $association['field'], $this->shortClassName($target)];
    }

    private function createIssue(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method, string $file, int $line, string $owner, string $field, string $target): IssueInterface
    {
        $caller = $this->shortClassName((string) $class->namespacedName) . '::' . $method->name->toString();

        return $this->issueFactory->createFromArray(new IssueData(
            type: IssueType::ENTITY_NEW_IN_ASSOCIATION->value,
            title: sprintf('New Entity in Required Association: %s::$%s', $owner, $field),
            description: sprintf(
                '%s() attaches a new %s to %s::$%s, which does not cascade persist. Unless the code persists it before '
                . 'flush(), Doctrine refuses the flush: "A new entity was found through the relationship".',
                $caller,
                $target,
                $owner,
                $field,
            ),
            severity: Severity::warning(),
            suggestion: null,
            queries: [],
        )->toArray() + ['file' => $file, 'line' => $line]);
    }
}
