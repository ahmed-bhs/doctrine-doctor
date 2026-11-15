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
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\Migrations\DependencyFactory;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Migration files of the project (the directories configured for doctrine/migrations, vendor excluded):
 * - up() drops a table or a column: the data goes with it;
 * - up() changes the schema and down() is missing or empty: the migration cannot be rolled back;
 * - the migration uses the ORM (EntityManager, repositories, entities): entities change after the migration
 *   is written, so it breaks or does something else when it runs later.
 */
class RiskyMigrationAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    private const array ORM_CALLS = ['getRepository', 'persist', 'flush', 'remove', 'find', 'findBy', 'findOneBy', 'createQueryBuilder', 'getEntityManager'];

    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?DependencyFactory $dependencyFactory = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            if (null === $this->dependencyFactory) {
                return;
            }

            foreach ($this->migrationFiles() as $file) {
                yield from $this->analyzeFile($file);
            }
        });
    }

    public function getName(): string
    {
        return 'Risky Migration Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects migrations that delete data, cannot be rolled back or depend on the ORM';
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = [];
        foreach ($this->dependencyFactory?->getConfiguration()->getMigrationDirectories() ?? [] as $directory) {
            $directory = str_replace('\\', '/', $directory);
            if (str_contains($directory, '/vendor/') || !is_dir($directory)) {
                continue;
            }
            foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return iterable<IntegrityIssue>
     */
    private function analyzeFile(string $file): iterable
    {
        try {
            $statements = new ParserFactory()->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        } catch (\PhpParser\Error) {
            return;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $statements = $traverser->traverse($statements);

        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
            $name = $class->name?->toString() ?? basename($file, '.php');
            $upMethod = $class->getMethod('up');
            if (null === $upMethod) {
                continue;
            }

            $dropped = $this->droppedObjects($upMethod);
            if ([] !== $dropped) {
                yield $this->issue(
                    IssueType::MIGRATION_DROPS_DATA,
                    $file,
                    $upMethod->getStartLine(),
                    $name,
                    'up',
                    Severity::warning(),
                    sprintf('Destructive Migration: %s', $name),
                    sprintf('%s::up() drops %s. The data is gone once it runs in production, and down() cannot bring it back.', $name, implode(', ', $dropped)),
                    'drops',
                );
            }

            $down = $class->getMethod('down');
            if ([] !== $this->sqlStatements($upMethod) && (null === $down || [] === $this->meaningfulStatements($down))) {
                yield $this->issue(
                    IssueType::MIGRATION_NOT_REVERSIBLE,
                    $file,
                    $upMethod->getStartLine(),
                    $name,
                    'up',
                    Severity::info(),
                    sprintf('Migration Without down(): %s', $name),
                    sprintf('%s changes the schema in up() but its down() does nothing. A deployment that goes wrong cannot be rolled back with migrations:migrate prev.', $name),
                    'irreversible',
                );
            }

            $ormCalls = $this->ormCalls($class);
            if ([] !== $ormCalls) {
                yield $this->issue(
                    IssueType::MIGRATION_USES_ORM,
                    $file,
                    $class->getStartLine(),
                    $name,
                    null,
                    Severity::warning(),
                    sprintf('Migration Uses the ORM: %s', $name),
                    sprintf('%s calls %s. Entities keep changing after the migration is written, so it fails or does something else when it runs on a later version of the code.', $name, implode(', ', array_map(static fn (string $call): string => $call . '()', $ormCalls))),
                    'orm',
                );
            }
        }
    }

    /**
     * @return list<string> SQL strings passed to addSql()
     */
    private function sqlStatements(Node\Stmt\ClassMethod $method): array
    {
        $sql = [];
        foreach (new NodeFinder()->findInstanceOf((array) $method->stmts, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && 'addSql' === $call->name->toString() && isset($call->args[0]) && $call->args[0] instanceof Node\Arg) {
                $sql[] = $call->args[0]->value instanceof Node\Scalar\String_ ? $call->args[0]->value->value : '';
            }
        }

        return $sql;
    }

    /**
     * @return list<Node\Stmt>
     */
    private function meaningfulStatements(Node\Stmt\ClassMethod $method): array
    {
        return array_values(array_filter((array) $method->stmts, static fn (Node\Stmt $stmt): bool => !$stmt instanceof Node\Stmt\Nop));
    }

    /**
     * @return list<string> "table x" or "column y" for each DROP in up()
     */
    private function droppedObjects(Node\Stmt\ClassMethod $upMethod): array
    {
        $dropped = [];
        foreach ($this->sqlStatements($upMethod) as $sql) {
            if (1 === preg_match('/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)/i', $sql, $match)) {
                $dropped[] = 'table ' . $match[1];
            }
            if (1 === preg_match_all('/\bDROP\s+(?:COLUMN\s+)?`?(?!INDEX\b|FOREIGN\b|CONSTRAINT\b|PRIMARY\b|TABLE\b|KEY\b)(\w+)/i', (string) preg_replace('/^\s*DROP\s+TABLE.*/i', '', $sql), $matches)) {
                foreach ($matches[1] as $column) {
                    $dropped[] = 'column ' . $column;
                }
            }
        }

        return array_values(array_unique($dropped));
    }

    /**
     * @return list<string>
     */
    private function ormCalls(Node\Stmt\Class_ $class): array
    {
        $calls = [];
        foreach (new NodeFinder()->findInstanceOf($class->stmts, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && \in_array($call->name->toString(), self::ORM_CALLS, true)) {
                $calls[] = $call->name->toString();
            }
        }

        return array_values(array_unique($calls));
    }

    private function issue(
        IssueType $type,
        string $file,
        int $line,
        string $class,
        ?string $method,
        Severity $severity,
        string $title,
        string $description,
        string $case,
    ): IntegrityIssue {
        return new IntegrityIssue([
            'type' => $type->value,
            'title' => $title,
            'description' => $description,
            'severity' => $severity,
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/risky_migration',
                context: ['case' => $case, 'class' => $class],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: $severity,
                    title: match ($case) {
                        'drops' => 'Keep the data before dropping it',
                        'irreversible' => 'Write the down() that undoes up()',
                        default => 'Write SQL, not entities, in migrations',
                    },
                    tags: ['migrations'],
                ),
            ),
            'queries' => [],
            'file' => $file,
            'line' => $line,
        ] + (null !== $method ? ['method' => $method] : []));
    }
}
