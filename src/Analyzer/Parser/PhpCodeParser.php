<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * PHP Code Parser using nikic/php-parser instead of fragile regex.
 *
 * This class provides a robust, maintainable way to analyze PHP code by:
 * - Parsing code into an Abstract Syntax Tree (AST)
 * - Using the Visitor Pattern to find specific patterns
 * - Caching parsed results for performance
 * - Providing type-safe, testable APIs
 *
 * Why this is better than regex:
 * ✅ Robust: Parses actual PHP syntax
 * ✅ Maintainable: Clear, object-oriented code
 * ✅ Type-safe: Full IDE support and PHPStan validation
 * ✅ Testable: Easy to write unit tests
 * ✅ Accurate: No false positives from comments/strings
 * ✅ Performant: AST caching
 * ✅ Debuggable: Clear error messages
 *
 * @see https://github.com/nikic/PHP-Parser
 */
final class PhpCodeParser
{
    /**
     * Maximum number of AST entries to cache.
     * Prevents memory exhaustion on large codebases.
     * With 1000 entries, memory usage ~20-30MB (acceptable).
     */
    private const MAX_CACHE_ENTRIES = 1000;

    /**
     * When cache is full, remove oldest 20% of entries.
     * This reduces cache churn while keeping memory bounded.
     */
    private const CACHE_EVICTION_RATIO = 0.2;

    private readonly Parser $parser;

    /** @var array<string, array<Stmt>|null> */
    private array $astCache = [];

    /**
     * Cache for analysis results with file modification time tracking.
     * Format: [cache_key => [result, mtime]]
     *
     * @var array<string, array{result: mixed, mtime: int|false}>
     */
    private array $analysisCache = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse PHP code into AST (Abstract Syntax Tree).
     *
     * @param string $code The PHP code to parse
     * @return array<Stmt>|null Array of statements, or null if parsing fails
     */
    public function parse(string $code): ?array
    {
        // Use xxh128 for better performance and lower collision rate than md5
        // xxh128 is ~10x faster than md5 and has better distribution
        $cacheKey = hash('xxh128', $code);

        if (isset($this->astCache[$cacheKey])) {
            return $this->astCache[$cacheKey];
        }

        // Evict old entries if cache is full (simple LRU-like strategy)
        if (count($this->astCache) >= self::MAX_CACHE_ENTRIES) {
            $this->evictOldestEntries();
        }

        try {
            $ast = $this->parser->parse($code);
            $this->astCache[$cacheKey] = $ast;
            return $ast;
        } catch (Error $error) {
            $this->logger?->warning('PhpCodeParser: Failed to parse PHP code', [
                'error' => $error->getMessage(),
                'line' => $error->getStartLine(),
            ]);
            $this->astCache[$cacheKey] = null;
            return null;
        }
    }

    /**
     * Check if a collection field is initialized in a method.
     *
     * This replaces complex regex patterns with clean AST traversal.
     * Detects patterns like:
     * - $this->field = new ArrayCollection()
     * - $this->field = []
     * - $this->initializeFieldCollection()
     *
     * @param ReflectionMethod $method The method to analyze
     * @param string $fieldName The field name to check
     * @return bool True if field is initialized
     */
    public function hasCollectionInitialization(ReflectionMethod $method, string $fieldName): bool
    {
        $code = $this->extractMethodCode($method);
        if (null === $code) {
            return false;
        }

        $ast = $this->parse($code);
        if (null === $ast) {
            return false;
        }

        $visitor = new Visitor\CollectionInitializationVisitor($fieldName);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->hasInitialization();
    }

    /**
     * Check if a method calls initialization methods.
     *
     * Detects patterns like:
     * - $this->initializeTranslationsCollection()
     * - $this->init*Collection()
     *
     * @param ReflectionMethod $method The method to analyze
     * @param string $methodNamePattern The method name pattern (supports wildcards)
     * @return bool True if method call is found
     */
    public function hasMethodCall(ReflectionMethod $method, string $methodNamePattern): bool
    {
        $code = $this->extractMethodCode($method);
        if (null === $code) {
            return false;
        }

        $ast = $this->parse($code);
        if (null === $ast) {
            return false;
        }

        $visitor = new Visitor\MethodCallVisitor($methodNamePattern);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->hasMethodCall();
    }

    /**
     * Detect insecure random number generator usage in a method.
     *
     * This replaces fragile regex with robust AST analysis.
     * Detects patterns like:
     * - rand(), mt_rand(), uniqid()
     * - md5(rand()), sha1(mt_rand())
     *
     * Benefits over regex:
     * - Ignores comments automatically (no false positives)
     * - Ignores string literals
     * - Detects nested patterns (md5(rand()))
     *
     * @param ReflectionMethod $method The method to analyze
     * @param array<string> $insecureFunctions List of insecure functions to detect
     * @return array<array{type: string, function: string, line: int}> Detected insecure calls
     */
    public function detectInsecureRandom(ReflectionMethod $method, array $insecureFunctions): array
    {
        $code = $this->extractMethodCode($method);
        if (null === $code) {
            return [];
        }

        $ast = $this->parse($code);
        if (null === $ast) {
            return [];
        }

        $visitor = new Visitor\InsecureRandomVisitor($insecureFunctions);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getInsecureCalls();
    }

    /**
     * Detect if a method exposes the entire object through serialization.
     *
     * This replaces fragile regex with robust AST analysis.
     * Detects patterns like:
     * - json_encode($this)
     * - serialize($this)
     *
     * Benefits over regex:
     * - Ignores comments automatically (no false positives)
     * - Ignores string literals
     * - Only detects actual function calls with $this argument
     *
     * @param ReflectionMethod $method The method to analyze
     * @return bool True if method exposes entire object
     */
    public function detectSensitiveExposure(ReflectionMethod $method): bool
    {
        $code = $this->extractMethodCode($method);
        if (null === $code) {
            return false;
        }

        $ast = $this->parse($code);
        if (null === $ast) {
            return false;
        }

        $visitor = new Visitor\SensitiveDataExposureVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->exposesEntireObject();
    }

    /**
     * Detect which sensitive fields are exposed in a method.
     *
     * This replaces fragile regex with robust AST analysis.
     * Detects patterns like:
     * - 'password' => $this->password (array key)
     * - $this->getPassword() (getter method call)
     * - $this->password (direct property access)
     *
     * Benefits over regex:
     * - Ignores comments automatically (no false positives)
     * - Ignores string literals in irrelevant contexts
     * - Type-safe detection of actual field exposure
     * - No false positives from field names in error messages
     *
     * @param ReflectionMethod $method The method to analyze
     * @param array<string> $sensitiveFields List of sensitive field names
     * @return array<string> List of exposed sensitive fields
     */
    public function detectExposedSensitiveFields(ReflectionMethod $method, array $sensitiveFields): array
    {
        $code = $this->extractMethodCode($method);
        if (null === $code) {
            return [];
        }

        $ast = $this->parse($code);
        if (null === $ast) {
            return [];
        }

        $visitor = new Visitor\SensitiveFieldExposureVisitor($sensitiveFields);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getExposedFields();
    }

    /**
     * Detect SQL injection patterns in a method.
     *
     * This replaces fragile regex with robust AST analysis.
     * Detects patterns like:
     * - String concatenation: $sql = "SELECT..." . $var
     * - Variable interpolation: $sql = "SELECT...$var"
     * - Missing parameters: $conn->executeQuery($sql) without params
     * - sprintf with user input: sprintf("SELECT...", $_GET['id'])
     *
     * Benefits over regex:
     * - Ignores comments automatically (no false positives)
     * - Ignores string literals in irrelevant contexts
     * - Type-safe detection of actual code patterns
     * - Proper scope and variable tracking
     *
     * @param ReflectionMethod $method The method to analyze
     * @return array{concatenation: bool, interpolation: bool, missing_parameters: bool, sprintf: bool}
     */
    public function detectSqlInjectionPatterns(ReflectionMethod $method): array
    {
        return $this->getCachedAnalysis($method, 'sql_injection', function () use ($method) {
            $code = $this->extractMethodCode($method);
            if (null === $code) {
                return [
                    'concatenation' => false,
                    'interpolation' => false,
                    'missing_parameters' => false,
                    'sprintf' => false,
                ];
            }

            $ast = $this->parse($code);
            if (null === $ast) {
                return [
                    'concatenation' => false,
                    'interpolation' => false,
                    'missing_parameters' => false,
                    'sprintf' => false,
                ];
            }

            $visitor = new Visitor\SqlInjectionPatternVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            return [
                'concatenation' => $visitor->hasConcatenationPattern(),
                'interpolation' => $visitor->hasInterpolationPattern(),
                'missing_parameters' => $visitor->hasMissingParametersPattern(),
                'sprintf' => $visitor->hasSprintfPattern(),
            ];
        });
    }

    /**
     * Clear the AST cache.
     * Useful for long-running processes to free memory.
     */
    public function clearCache(): void
    {
        $this->astCache = [];
        $this->analysisCache = [];

        $this->logger?->debug('PhpCodeParser: All caches cleared');
    }

    /**
     * Get cache statistics.
     *
     * @return array{ast_entries: int, analysis_entries: int, memory_bytes: int}
     */
    public function getCacheStats(): array
    {
        $memoryBytes = strlen(serialize($this->astCache)) + strlen(serialize($this->analysisCache));

        return [
            'ast_entries' => count($this->astCache),
            'analysis_entries' => count($this->analysisCache),
            'memory_bytes' => $memoryBytes,
            'memory_mb' => round($memoryBytes / 1024 / 1024, 2),
        ];
    }

    /**
     * Evict oldest cache entries when limit is reached.
     * Removes 20% of entries to reduce cache churn.
     */
    private function evictOldestEntries(): void
    {
        $entriesToRemove = (int) (self::MAX_CACHE_ENTRIES * self::CACHE_EVICTION_RATIO);

        // Remove first N entries (oldest in insertion order)
        // PHP arrays maintain insertion order, so this works as simple FIFO
        $this->astCache = array_slice($this->astCache, $entriesToRemove, null, true);

        $this->logger?->debug('PhpCodeParser: Evicted cache entries', [
            'removed' => $entriesToRemove,
            'remaining' => count($this->astCache),
        ]);
    }

    /**
     * Extract source code from a ReflectionMethod.
     *
     * @param ReflectionMethod $method The method to extract
     * @return string|null The method source code, or null if unavailable
     */
    private function extractMethodCode(ReflectionMethod $method): ?string
    {
        try {
            $filename = $method->getFileName();
            if (false === $filename || !file_exists($filename)) {
                return null;
            }

            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (false === $startLine || false === $endLine) {
                return null;
            }

            $source = file($filename);
            if (false === $source) {
                return null;
            }

            $lineCount = $endLine - $startLine + 1;

            // Safety check: skip very large methods
            if ($lineCount > 500) {
                $this->logger?->debug('PhpCodeParser: Method too large', [
                    'method' => $method->getName(),
                    'lines' => $lineCount,
                ]);
                return null;
            }

            $methodCode = implode('', array_slice($source, $startLine - 1, $lineCount));

            // Safety check: skip very large code blocks
            if (strlen($methodCode) > 50000) {
                $this->logger?->debug('PhpCodeParser: Method code too large', [
                    'method' => $method->getName(),
                    'bytes' => strlen($methodCode),
                ]);
                return null;
            }

            // IMPORTANT: Wrap in valid PHP context
            // The parser needs complete PHP code, not just a method signature
            // We wrap it in a dummy class so it can be parsed properly
            $wrappedCode = "<?php\nclass DummyClass {\n" . $methodCode . "\n}\n";

            return $wrappedCode;
        } catch (\Throwable $throwable) {
            $this->logger?->debug('PhpCodeParser: Error extracting code', [
                'exception' => $throwable::class,
                'method' => $method->getName(),
            ]);
            return null;
        }
    }

    /**
     * Get cached analysis result with automatic invalidation on file changes.
     *
     * @template T
     * @param ReflectionMethod $method The method being analyzed
     * @param string $analysisType Unique identifier for this analysis type
     * @param callable(): T $callback Function to compute the result if cache miss
     * @return T
     */
    private function getCachedAnalysis(ReflectionMethod $method, string $analysisType, callable $callback): mixed
    {
        $filename = $method->getFileName();
        if (false === $filename) {
            // Method from internal class or eval(), can't cache
            return $callback();
        }

        $mtime = filemtime($filename);
        $cacheKey = sprintf(
            '%s:%s::%s:%d',
            $analysisType,
            $method->getDeclaringClass()->getName(),
            $method->getName(),
            $mtime ?: 0, // mtime for auto-invalidation
        );

        // Check cache
        if (isset($this->analysisCache[$cacheKey])) {
            $cached = $this->analysisCache[$cacheKey];

            // Verify file hasn't changed (double-check)
            if ($cached['mtime'] === $mtime) {
                $this->logger?->debug('PhpCodeParser: Analysis cache HIT', [
                    'type' => $analysisType,
                    'method' => $method->getName(),
                ]);

                return $cached['result'];
            }

            // File changed, invalidate
            unset($this->analysisCache[$cacheKey]);
        }

        // Cache miss or invalidated - compute result
        $this->logger?->debug('PhpCodeParser: Analysis cache MISS', [
            'type' => $analysisType,
            'method' => $method->getName(),
        ]);

        $result = $callback();

        // Store in cache
        $this->analysisCache[$cacheKey] = [
            'result' => $result,
            'mtime' => $mtime,
        ];

        return $result;
    }
}
