<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\SecurityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
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
    // Methods that execute SQL
    private const array SQL_EXECUTION_METHODS = [
        'executeQuery',
        'executeStatement',
        'exec',
        'query',
        'prepare',
        'createNativeQuery',
    ];

    private readonly PhpCodeParser $phpCodeParser;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
        ?PhpCodeParser $phpCodeParser = null,
    ) {
        $this->phpCodeParser = $phpCodeParser ?? new PhpCodeParser($logger);
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
                    // Analyze runtime queries from the collection
                    Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                    foreach ($queryDataCollection as $queryData) {
                        $issue = $this->analyzeQuery($queryData);
                        if (null !== $issue) {
                            yield $issue;
                        }
                    }

                    // Only perform static code analysis if no specific queries were provided
                    // This allows tests to check specific queries without triggering full codebase scan
                    if ($queryDataCollection->isEmpty()) {
                        $metadataFactory = $this->entityManager->getMetadataFactory();
                        $allMetadata     = $metadataFactory->getAllMetadata();

                        Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                        foreach ($allMetadata as $metadata) {
                            $entityIssues = $this->analyzeEntity($metadata);

                            Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                            foreach ($entityIssues as $entityIssue) {
                                yield $entityIssue;
                            }
                        }

                        // Also analyze repositories
                        Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                        foreach ($allMetadata as $metadata) {
                            $repositoryClass = $metadata->customRepositoryClassName;

                            if (null !== $repositoryClass && class_exists($repositoryClass)) {
                                $repositoryIssues = $this->analyzeClass($repositoryClass);

                                Assert::isIterable($repositoryIssues, '$repositoryIssues must be iterable');

                                foreach ($repositoryIssues as $repositoryIssue) {
                                    yield $repositoryIssue;
                                }
                            }
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
     * Analyze a single query for SQL injection vulnerabilities.
     */
    private function analyzeQuery(\AhmedBhs\DoctrineDoctor\DTO\QueryData $queryData): ?SecurityIssue
    {
        $sql = $queryData->sql;
        $params = $queryData->params;

        // Check if this is a raw SQL query (SELECT, INSERT, UPDATE, DELETE)
        if (1 !== preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)/i', $sql)) {
            return null;
        }

        // If query has parameters, it's likely using prepared statements - generally safe
        if (!empty($params)) {
            return null;
        }

        // Check for SQL injection patterns
        $hasInjectionPattern = $this->detectRuntimeInjectionPatterns($sql);

        if (!$hasInjectionPattern) {
            return null;
        }

        // Create issue for detected SQL injection vulnerability
        $description = sprintf(
            'Detected potential SQL injection in query: %s. ' .
            'The query contains suspicious patterns and no bound parameters. ' .
            'Always use prepared statements with parameter binding to prevent SQL injection attacks.',
            substr($sql, 0, 100) . (strlen($sql) > 100 ? '...' : ''),
        );

        return new SecurityIssue([
            'title' => 'SQL Injection: Suspicious query without parameters',
            'description' => $description,
            'severity' => 'critical',
            'suggestion' => $this->createRuntimeQuerySuggestion($sql),
            'backtrace' => $queryData->backtrace,
            'queries' => [$queryData],
        ]);
    }

    /**
     * Detect SQL injection patterns in runtime queries.
     */
    private function detectRuntimeInjectionPatterns(string $sql): bool
    {
        $patterns = [
            // SQL comment injection
            '/--/',
            '/\/\*.*?\*\//',
            // Classic injection patterns
            '/\'\s*OR\s*[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+/i',
            '/\'\s*OR\s*TRUE/i',
            // UNION injection
            '/UNION\s+SELECT/i',
            // Stacked queries
            '/;\s*DROP\s+TABLE/i',
            '/;\s*DELETE\s+FROM/i',
            // Time-based blind injection
            '/SLEEP\s*\(/i',
            '/BENCHMARK\s*\(/i',
        ];

        Assert::isIterable($patterns, '$patterns must be iterable');
        return array_any($patterns, fn ($pattern) => 1 === preg_match($pattern, $sql));
    }

    /**
     * Create suggestion for runtime query issues.
     */
    private function createRuntimeQuerySuggestion(string $sql): SuggestionInterface
    {
        $code = "// VULNERABLE - Query without parameters:\n";
        $code .= "// \$connection->executeQuery('{$sql}');\n\n";
        $code .= "// SECURE - Use prepared statements:\n";
        $code .= "\$sql = 'SELECT * FROM table WHERE column = :value';\n";
        $code .= "\$result = \$connection->executeQuery(\$sql, ['value' => \$userInput]);\n";

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Use parameterized queries with bound parameters',
            code: $code,
            filePath: 'Runtime Query',
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

        return $this->detectAllInjectionPatterns($source, $className, $reflectionMethod);
    }

    /**
     * Detect all SQL injection patterns using PhpCodeParser.
     * @return array<SecurityIssue>
     */
    private function detectAllInjectionPatterns(
        string $source,
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
        $patterns = $this->phpCodeParser->detectSqlInjectionPatterns($reflectionMethod);

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
        $shortClassName = $this->getShortClassName($className);

        $description = sprintf(
            'Method "%s::%s()" uses string concatenation to build SQL queries. ' .
            'This is a CRITICAL SQL injection vulnerability! Attackers can inject malicious SQL code ' .
            'to read, modify, or delete data. NEVER concatenate user input into SQL queries. ' .
            'Always use parameterized queries with bound parameters.',
            $shortClassName,
            $methodName,
        );

        return new SecurityIssue([
            'title'       => sprintf('SQL Injection: String concatenation in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createParameterizedQuerySuggestion($className, $methodName, $reflectionMethod),
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
        $shortClassName = $this->getShortClassName($className);

        $description = sprintf(
            'Method "%s::%s()" uses variable interpolation in SQL query strings. ' .
            'This creates a SQL injection vulnerability. Even with type casting like (int), ' .
            'this is NOT safe for all data types. Use parameterized queries instead.',
            $shortClassName,
            $methodName,
        );

        return new SecurityIssue([
            'title'       => sprintf('SQL Injection: Variable interpolation in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createParameterizedQuerySuggestion($className, $methodName, $reflectionMethod),
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
        $shortClassName = $this->getShortClassName($className);

        $description = sprintf(
            'Method "%s::%s()" calls %s() with a dynamically built SQL query but no parameter binding. ' .
            'If this SQL query includes user input, it is vulnerable to SQL injection. ' .
            'Pass parameters as the second argument to %s().',
            $shortClassName,
            $methodName,
            $sqlMethod,
            $sqlMethod,
        );

        return new SecurityIssue([
            'title'       => sprintf('Potential SQL Injection in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createParameterBindingSuggestion($className, $methodName, $sqlMethod, $reflectionMethod),
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
        $shortClassName = $this->getShortClassName($className);

        $description = sprintf(
            'Method "%s::%s()" uses sprintf() to format SQL queries with user input. ' .
            'This is vulnerable to SQL injection! sprintf() does NOT escape SQL special characters. ' .
            'Use parameterized queries with bound parameters instead.',
            $shortClassName,
            $methodName,
        );

        return new SecurityIssue([
            'title'       => sprintf('SQL Injection via sprintf() in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createParameterizedQuerySuggestion($className, $methodName, $reflectionMethod),
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
        $shortClassName = $this->getShortClassName($className);

        $code = "// In {$shortClassName}::{$methodName}():

";
        $code .= "// VULNERABLE - String concatenation:
";
        $code .= "// \$sql = \"SELECT * FROM users WHERE username = '\" . \$username . \"'\";
";
        $code .= "// \$sql = \"SELECT * FROM users WHERE id = \" . (int)\$id; // Still unsafe!
";
        $code .= "// \$sql = \"SELECT * FROM users WHERE username = '\$username'\"; // Interpolation
";
        $code .= "// \$connection->executeQuery(\$sql); // 💀 SQL INJECTION!

";

        $code .= "//  SECURE - Parameterized query:

";
        $code .= "// Using Doctrine Connection
";
        $code .= "\$sql = 'SELECT * FROM users WHERE username = :username';
";
        $code .= "\$result = \$connection->executeQuery(\$sql, [
";
        $code .= "    'username' => \$username // Parameter is properly escaped
";
        $code .= "]);

";

        $code .= "// Multiple parameters
";
        $code .= "\$sql = 'SELECT * FROM orders WHERE user_id = :userId AND status = :status';
";
        $code .= "\$result = \$connection->executeQuery(\$sql, [
";
        $code .= "    'userId' => \$userId,
";
        $code .= "    'status' => \$status
";
        $code .= "]);

";

        $code .= "// Using Query Builder (even safer)
";
        $code .= "\$qb = \$connection->createQueryBuilder();
";
        $code .= "\$result = \$qb->select('*')
";
        $code .= "    ->from('users')
";
        $code .= "    ->where('username = :username')
";
        $code .= "    ->setParameter('username', \$username)
";
        $code .= '    ->executeQuery();';

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Use parameterized queries with bound parameters',
            code: $code,
            filePath: $this->getFileLocation($reflectionMethod),
        );
    }

    private function createParameterBindingSuggestion(
        string $className,
        string $methodName,
        string $sqlMethod,
        \ReflectionMethod $reflectionMethod,
    ): SuggestionInterface {
        $shortClassName = $this->getShortClassName($className);

        $code = "// In {$shortClassName}::{$methodName}():

";
        $code .= "// VULNERABLE:
";
        $code .= "// \$sql = \"SELECT * FROM users WHERE id = \" . \$id;
";
        $code .= "// \$connection->{$sqlMethod}(\$sql); // No parameters!

";

        $code .= "//  SECURE:
";
        $code .= "\$sql = 'SELECT * FROM users WHERE id = :id';
";
        $code .= "\$connection->{$sqlMethod}(\$sql, ['id' => \$id]); // Parameters bound

";

        $code .= "// Or with explicit types:
";
        $code .= sprintf('$connection->%s($sql, [\'id\' => $id], [\'id\' => ' . \PDO::class . '::PARAM_INT]);', $sqlMethod);

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Add parameter binding to prevent SQL injection',
            code: $code,
            filePath: $this->getFileLocation($reflectionMethod),
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

        $source = file($filename);

        if (false === $source) {
            return null;
        }

        return implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
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
}
