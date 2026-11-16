<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\DQLPatternMatcher;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\ProjectSourceScanner;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\SecurityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Webmozart\Assert\Assert;

/**
 * Detects potential SQL injection vulnerabilities in raw SQL queries.
 * Checks for:
 * - String concatenation in SQL queries
 * - executeQuery/executeStatement without parameters
 * - Direct variable interpolation in SQL strings
 * - Missing parameter binding
 */
class SQLInjectionInRawQueriesAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    use ShortClassNameTrait;

    // Methods that execute SQL
    private const array SQL_EXECUTION_METHODS = [
        'executeQuery',
        'executeStatement',
        'exec',
        'query',
        'prepare',
        'createNativeQuery',
        'fetchAllAssociative',
        'fetchAllAssociativeIndexed',
        'fetchAllKeyValue',
        'fetchAllNumeric',
        'fetchAssociative',
        'fetchFirstColumn',
        'fetchNumeric',
        'fetchOne',
        'iterateAssociative',
        'iterateNumeric',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
        private ?PhpCodeParser $phpCodeParser = null,
        private readonly DQLPatternMatcher $dqlPatternMatcher = new DQLPatternMatcher(),
        private readonly ?ProjectSourceScanner $projectSourceScanner = null,
    ) {
    }

    /**
     * @return IssueCollection<SecurityIssue>
     */
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                try {
                    foreach ($queryDataCollection as $queryData) {
                        $issue = $this->analyzeQuery($queryData);
                        if (null !== $issue) {
                            yield $issue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('SQLInjectionInRawQueriesAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    /**
     * Analyze mapped entities and their custom repositories for unsafe SQL
     * construction. This source scan is run by the static CI analyzer only.
     *
     * @return IssueCollection<SecurityIssue>
     */
    public function analyzeSourceCode(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

                    foreach ($allMetadata as $metadata) {
                        foreach ($this->analyzeEntity($metadata) as $issue) {
                            yield $issue;
                        }
                    }

                    $analyzed = [];
                    foreach ($allMetadata as $metadata) {
                        $analyzed[$metadata->getName()] = true;
                        $repositoryClass = $metadata->customRepositoryClassName;

                        if (null !== $repositoryClass && class_exists($repositoryClass)) {
                            $analyzed[$repositoryClass] = true;
                            foreach ($this->analyzeClass($repositoryClass) as $issue) {
                                yield $issue;
                            }
                        }
                    }

                    // Raw SQL is also built in controllers, commands and services of the application.
                    foreach ($this->projectSourceScanner?->classes() ?? [] as ['class' => $class]) {
                        $className = (string) $class->namespacedName;
                        if (isset($analyzed[$className]) || !class_exists($className)) {
                            continue;
                        }
                        $analyzed[$className] = true;

                        foreach ($this->analyzeClass($className) as $issue) {
                            yield $issue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('SQLInjectionInRawQueriesAnalyzer source scan failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    private function analyzeQuery(\AhmedBhs\DoctrineDoctor\DTO\QueryData $queryData): ?SecurityIssue
    {
        $sql = $queryData->sql;
        $params = $queryData->params;

        if (1 !== preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)/i', $sql)) {
            return null;
        }

        if (!empty($params)) {
            return null;
        }

        if ($this->dqlPatternMatcher->hasDoctrineSQLPattern($sql)) {
            return null;
        }

        $hasActiveAttack = $this->detectActiveAttackPatterns($sql);

        if ($hasActiveAttack) {
            return new SecurityIssue([
                'title' => 'SQL Injection: Active attack pattern detected',
                'description' => sprintf(
                    'The query contains an injection pattern (such as OR 1=1 or UNION SELECT) and no bound parameter: %s. '
                    . 'Input reached the SQL text unescaped, so it can read or modify any data.',
                    substr($sql, 0, 100) . (strlen($sql) > 100 ? '...' : ''),
                ),
                'severity' => 'critical',
                'suggestion' => $this->createRuntimeQuerySuggestion($sql),
                'backtrace' => $queryData->backtrace,
                'queries' => [$queryData],
            ]);
        }

        if ($this->hasLiteralInWhereClause($sql)) {
            return new SecurityIssue([
                'title' => 'SQL Injection Risk: Unparameterized literal in raw query',
                'description' => sprintf(
                    'The raw SQL query has literal values in its WHERE clause and no bound parameter: %s. '
                    . 'It was built by string concatenation, so any user input in it can change the query (SQL injection).',
                    substr($sql, 0, 100) . (strlen($sql) > 100 ? '...' : ''),
                ),
                'severity' => 'critical',
                'suggestion' => $this->createRuntimeQuerySuggestion($sql),
                'backtrace' => $queryData->backtrace,
                'queries' => [$queryData],
            ]);
        }

        return null;
    }

    private function detectActiveAttackPatterns(string $sql): bool
    {
        $patterns = [
            '/\'\s*OR\s*[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+/i',
            '/\'\s*OR\s*TRUE/i',
            '/UNION\s+SELECT/i',
            '/;\s*DROP\s+TABLE/i',
            '/;\s*DELETE\s+FROM/i',
            '/SLEEP\s*\(/i',
            '/BENCHMARK\s*\(/i',
        ];

        return array_any($patterns, fn ($pattern) => 1 === preg_match($pattern, $sql));
    }

    private function hasLiteralInWhereClause(string $sql): bool
    {
        if (preg_match_all("/WHERE\s+.+?=\s*'([^']*)'/i", $sql, $matches) > 0) {
            foreach ($matches[1] as $literal) {
                if ($this->literalLooksSuspicious($literal)) {
                    return true;
                }
            }
        }

        if (preg_match_all('/WHERE\s+.+?=\s*"([^"]*)"/i', $sql, $matches) > 0) {
            foreach ($matches[1] as $literal) {
                if ($this->literalLooksSuspicious($literal)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function literalLooksSuspicious(string $literal): bool
    {
        if (preg_match('/[;\-\-]|\bOR\b|\bAND\b|\bUNION\b|\bSELECT\b|\bDROP\b/i', $literal) > 0) {
            return true;
        }

        if (strlen($literal) > 64) {
            return true;
        }

        return false;
    }

    /**
     * Create suggestion for runtime query issues.
     */
    private function createRuntimeQuerySuggestion(string $sql): SuggestionInterface
    {
        $code = "// Before\n\$connection->executeQuery('{$sql}');\n\n";
        $code .= "// After: the value is bound, never written into the SQL\n";
        $code .= "\$result = \$connection->executeQuery('SELECT * FROM table WHERE column = :value', ['value' => \$userInput]);\n";

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/code_suggestion',
            context: [
                'title' => 'Bind the values as parameters',
                'description' => 'Pass every value as a parameter: a parameter is never read as SQL.',
                'code' => $code,
                'file_path' => '',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Use Parameterized Queries',
                tags: ['code-quality'],
            ),
        );
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<SecurityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        return $this->analyzeClass($classMetadata->getName());
    }

    /**
     * @return array<SecurityIssue>
     */
    private function analyzeClass(string $className): array
    {

        $issues = [];

        try {
            Assert::classExists($className);
            $reflectionClass = new ReflectionClass($className);

            foreach ($reflectionClass->getMethods() as $reflectionMethod) {
                // Skip methods from parent framework classes
                if ($reflectionMethod->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $methodIssues = $this->analyzeMethod($className, $reflectionMethod);
                $issues       = array_merge($issues, $methodIssues);
            }
        } catch (\ReflectionException) {
            // Class doesn't exist
        }

        return $issues;
    }

    /**
     * @return array<SecurityIssue>
     */
    private function analyzeMethod(string $className, \ReflectionMethod $reflectionMethod): array
    {
        $source = $this->getMethodSource($reflectionMethod);

        if (null === $source || !$this->usesSqlExecution($source)) {
            return [];
        }

        return $this->detectAllInjectionPatterns($className, $reflectionMethod);
    }

    /**
     * Detect all SQL injection patterns using PhpCodeParser.
     * @return array<SecurityIssue>
     */
    private function detectAllInjectionPatterns(
        string $className,
        \ReflectionMethod $reflectionMethod,
    ): array {
        $issues = [];

        // Use PhpCodeParser instead of fragile regex
        // This provides robust AST-based detection that handles:
        // - String concatenation: $sql = "SELECT..." . $var
        // - Variable interpolation: $sql = "SELECT...$var"
        // - Missing parameters: $conn->executeQuery($sql) without params
        // - sprintf with user input: sprintf("SELECT...", $_GET['id'])
        // - Ignores comments automatically (no false positives)
        // - Type-safe detection with proper scope analysis
        $patterns = $this->getPhpCodeParser()->detectSqlInjectionPatterns($reflectionMethod);

        if ($patterns['concatenation']) {
            $issues[] = $this->createConcatenationIssue($className, $reflectionMethod->getName(), $reflectionMethod);
        }

        if ($patterns['interpolation']) {
            $issues[] = $this->createInterpolationIssue($className, $reflectionMethod->getName(), $reflectionMethod);
        }

        if ($patterns['missing_parameters']) {
            // Determine which SQL method was used (for better error message)
            $sqlMethod = 'executeQuery'; // Default, will be detected by visitor
            $issues[] = $this->createMissingParametersIssue($className, $reflectionMethod->getName(), $sqlMethod, $reflectionMethod);
        }

        if ($patterns['sprintf']) {
            $issues[] = $this->createSprintfIssue($className, $reflectionMethod->getName(), $reflectionMethod);
        }

        return $issues;
    }

    /**
     * Check if method uses SQL execution methods.
     */
    private function usesSqlExecution(string $source): bool
    {
        return array_any(self::SQL_EXECUTION_METHODS, fn ($sqlMethod) => str_contains($source, (string) $sqlMethod));
    }

    private function createConcatenationIssue(
        string $className,
        string $methodName,
        \ReflectionMethod $reflectionMethod,
    ): SecurityIssue {
        $shortClassName = $this->shortClassName($className);

        $description = sprintf(
            'Method "%s::%s()" builds SQL by string concatenation. ' .
            'Any user input in the concatenated values can change the query and read or modify data (SQL injection).',
            $shortClassName,
            $methodName,
        );

        return new SecurityIssue([
            'title'       => sprintf('SQL Injection: String concatenation in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createParameterizedQuerySuggestion($className, $methodName, $reflectionMethod),
            'class'       => $className,
            'method'      => $methodName,
            'backtrace'   => [
                'file' => $reflectionMethod->getFileName(),
                'line' => $reflectionMethod->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createInterpolationIssue(
        string $className,
        string $methodName,
        \ReflectionMethod $reflectionMethod,
    ): SecurityIssue {
        $shortClassName = $this->shortClassName($className);

        $description = sprintf(
            'Method "%s::%s()" builds SQL with variable interpolation. ' .
            'Any user input in the interpolated variables can change the query (SQL injection).',
            $shortClassName,
            $methodName,
        );

        return new SecurityIssue([
            'title'       => sprintf('SQL Injection: Variable interpolation in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createParameterizedQuerySuggestion($className, $methodName, $reflectionMethod),
            'class'       => $className,
            'method'      => $methodName,
            'backtrace'   => [
                'file' => $reflectionMethod->getFileName(),
                'line' => $reflectionMethod->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createMissingParametersIssue(
        string $className,
        string $methodName,
        string $sqlMethod,
        \ReflectionMethod $reflectionMethod,
    ): SecurityIssue {
        $shortClassName = $this->shortClassName($className);

        $description = sprintf(
            'Method "%s::%s()" calls %s() with a dynamically built SQL query and no parameter binding. ' .
            'If the query includes user input, it is open to SQL injection.',
            $shortClassName,
            $methodName,
            $sqlMethod,
        );

        return new SecurityIssue([
            'title'       => sprintf('Potential SQL Injection in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createParameterBindingSuggestion($className, $methodName, $sqlMethod, $reflectionMethod),
            'class'       => $className,
            'method'      => $methodName,
            'backtrace'   => [
                'file' => $reflectionMethod->getFileName(),
                'line' => $reflectionMethod->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createSprintfIssue(
        string $className,
        string $methodName,
        \ReflectionMethod $reflectionMethod,
    ): SecurityIssue {
        $shortClassName = $this->shortClassName($className);

        $description = sprintf(
            'Method "%s::%s()" formats SQL with sprintf(). ' .
            'sprintf() does not escape SQL special characters, so any user input in the arguments is open to SQL injection.',
            $shortClassName,
            $methodName,
        );

        return new SecurityIssue([
            'title'       => sprintf('SQL Injection via sprintf() in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createParameterizedQuerySuggestion($className, $methodName, $reflectionMethod),
            'class'       => $className,
            'method'      => $methodName,
            'backtrace'   => [
                'file' => $reflectionMethod->getFileName(),
                'line' => $reflectionMethod->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createParameterizedQuerySuggestion(
        string $className,
        string $methodName,
        \ReflectionMethod $reflectionMethod,
    ): SuggestionInterface {
        $shortClassName = $this->shortClassName($className);

        $code = <<<PHP
            use Doctrine\\DBAL\\ArrayParameterType;

            // In {$shortClassName}::{$methodName}()
            // Before: the value becomes part of the SQL
            \$rows = \$connection->fetchAllAssociative("SELECT * FROM orders WHERE status = '" . \$status . "'");

            // After: the SQL and the value travel separately, the value can never become SQL
            \$rows = \$connection->fetchAllAssociative(
                'SELECT * FROM orders WHERE status = :status',
                ['status' => \$status],
            );

            // A list of values
            \$rows = \$connection->fetchAllAssociative(
                'SELECT * FROM orders WHERE id IN (:ids)',
                ['ids' => \$ids],
                ['ids' => ArrayParameterType::INTEGER],
            );
            PHP;

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/code_suggestion',
            context: [
                'title' => 'Bind the values as parameters',
                'description' => 'Pass every value as a parameter: a parameter is never read as SQL. A table or column name cannot be a parameter: check it against a list of allowed names.',
                'code' => $code,
                'file_path' => $this->getFileLocation($reflectionMethod),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Use Parameterized Queries',
                tags: ['code-quality'],
            ),
        );
    }

    private function createParameterBindingSuggestion(
        string $className,
        string $methodName,
        string $sqlMethod,
        \ReflectionMethod $reflectionMethod,
    ): SuggestionInterface {
        $shortClassName = $this->shortClassName($className);

        $code = <<<PHP
            use Doctrine\\DBAL\\ParameterType;

            // In {$shortClassName}::{$methodName}()
            // Before: no parameter, the value is written into the SQL
            \$connection->{$sqlMethod}('SELECT * FROM orders WHERE id = ' . \$id);

            // After
            \$connection->{$sqlMethod}('SELECT * FROM orders WHERE id = :id', ['id' => \$id], ['id' => ParameterType::INTEGER]);
            PHP;

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/code_suggestion',
            context: [
                'title' => 'Bind the values as parameters',
                'description' => 'Pass the value as a parameter instead of writing it into the SQL.',
                'code' => $code,
                'file_path' => $this->getFileLocation($reflectionMethod),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Bind Parameters to Prevent SQL Injection',
                tags: ['code-quality'],
            ),
        );
    }

    private function getMethodSource(\ReflectionMethod $reflectionMethod): ?string
    {
        $filename = $reflectionMethod->getFileName();

        if (false === $filename) {
            return null;
        }

        $startLine = $reflectionMethod->getStartLine();
        $endLine   = $reflectionMethod->getEndLine();

        if (false === $startLine || false === $endLine) {
            return null;
        }

        try {
            $file = new \SplFileObject($filename, 'r');
        } catch (\Throwable) {
            return null;
        }

        $lines = [];
        $file->seek($startLine - 1);

        for ($i = $startLine; $i <= $endLine && !$file->eof(); ++$i) {
            $line = $file->current();
            if (is_string($line)) {
                $lines[] = $line;
            }
            $file->next();
        }

        return implode('', $lines);
    }

    private function getFileLocation(\ReflectionMethod $reflectionMethod): string
    {
        $filename = $reflectionMethod->getFileName();
        $line = $reflectionMethod->getStartLine();

        if (false === $filename || false === $line) {
            return 'unknown';
        }

        return sprintf('%s:%d', $filename, $line);
    }

    private function getPhpCodeParser(): PhpCodeParser
    {
        return $this->phpCodeParser ??= new PhpCodeParser($this->logger);
    }
}
