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
use Webmozart\Assert\Assert;

/**
 * Detects usage of insecure random number generators for security-sensitive operations.
 * Checks for:
 * - rand(), mt_rand(), uniqid() used for tokens/secrets
 * - Predictable token generation
 * - Insufficient entropy for cryptographic operations
 */
class InsecureRandomAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    // Insecure functions that should not be used for security
    private const array INSECURE_FUNCTIONS = [
        'rand',
        'mt_rand',
        'srand',
        'mt_srand',
        'uniqid',
        'microtime',
        'time',
    ];

    // Context patterns that indicate security-sensitive usage
    private const array SENSITIVE_CONTEXTS = [
        'token',
        'secret',
        'key',
        'password',
        'salt',
        'nonce',
        'csrf',
        'reset',
        'verification',
        'api',
        'auth',
        'session',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
        /**
         * @readonly
         */
        private ?PhpCodeParser $phpCodeParser = null,
    ) {
        // Dependency injection with fallback for backwards compatibility
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
            function () {
                try {
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
                } catch (\Throwable $throwable) {
                    $this->logger?->error('InsecureRandomAnalyzer failed', [
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
     * @return array<SecurityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues          = [];
        $entityClass     = $classMetadata->getName();
        $reflectionClass = $classMetadata->getReflectionClass();

        // Analyze all methods
        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            // Skip inherited methods from framework classes
            if ($reflectionMethod->getDeclaringClass()->getName() !== $entityClass) {
                continue;
            }

            $methodIssues = $this->analyzeMethod($entityClass, $reflectionMethod);
            $issues       = array_merge($issues, $methodIssues);
        }

        return $issues;
    }

    /**
     * @return array<SecurityIssue>
     */
    private function analyzeMethod(string $entityClass, \ReflectionMethod $reflectionMethod): array
    {
        $issues = [];
        $source = $this->getMethodSource($reflectionMethod);

        if (null === $source) {
            return [];
        }

        $methodName = $reflectionMethod->getName();

        // Check if method name or context suggests security-sensitive operation
        if (!$this->isSensitiveContext($methodName, $source)) {
            return [];
        }

        // Use PHP Parser instead of regex for robust detection
        // This eliminates false positives from comments and strings
        if (null === $this->phpCodeParser) {
            return [];
        }

        $insecureCalls = $this->phpCodeParser->detectInsecureRandom($reflectionMethod, self::INSECURE_FUNCTIONS);

        // Track which functions we've already reported to avoid duplicates
        $reportedFunctions = [];

        foreach ($insecureCalls as $call) {
            if ('direct_call' === $call['type']) {
                // Report direct insecure function call
                $function = $call['function'];

                // Only report each function once
                if (!isset($reportedFunctions[$function])) {
                    $issues[] = $this->createInsecureRandomIssue(
                        $entityClass,
                        $methodName,
                        $function,
                        $reflectionMethod,
                    );
                    $reportedFunctions[$function] = true;
                }
            } elseif ('weak_hash' === $call['type']) {
                // Report weak hash with insecure random (md5(rand()), etc.)
                $issues[] = $this->createWeakHashIssue($entityClass, $methodName, $reflectionMethod);
            }
        }

        return $issues;
    }

    private function isSensitiveContext(string $methodName, string $source): bool
    {
        $lowerMethodName = strtolower($methodName);
        $lowerSource     = strtolower($source);
        return array_any(self::SENSITIVE_CONTEXTS, fn ($context) => str_contains($lowerMethodName, (string) $context) || str_contains($lowerSource, (string) $context));
    }

    private function createInsecureRandomIssue(
        string $entityClass,
        string $methodName,
        string $function,
        \ReflectionMethod $reflectionMethod,
    ): SecurityIssue {
        $shortClassName = $this->getShortClassName($entityClass);

        $description = sprintf(
            'Method "%s::%s()" uses insecure random function "%s()" for security-sensitive operations. ' .
            'Functions like rand(), mt_rand(), and uniqid() are NOT cryptographically secure and can be ' .
            'predicted by attackers. This can lead to token prediction, session hijacking, or authentication bypass. ' .
            'Use random_bytes() or random_int() instead, which use CSPRNG (Cryptographically Secure Pseudo-Random Number Generator).',
            $shortClassName,
            $methodName,
            $function,
        );

        return new SecurityIssue([
            'title'       => sprintf('Insecure random generator in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createSecureRandomSuggestion($entityClass, $methodName, $function, $reflectionMethod),
            'backtrace'   => [
                'file' => $reflectionMethod->getFileName(),
                'line' => $reflectionMethod->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createWeakHashIssue(
        string $entityClass,
        string $methodName,
        \ReflectionMethod $reflectionMethod,
    ): SecurityIssue {
        $shortClassName = $this->getShortClassName($entityClass);

        $description = sprintf(
            'Method "%s::%s()" combines weak random functions with hashing (e.g., md5(rand())). ' .
            'This does NOT make it secure! Hashing a predictable value produces a predictable hash. ' .
            'Attackers can still predict the output. Use random_bytes() for cryptographic randomness.',
            $shortClassName,
            $methodName,
        );

        return new SecurityIssue([
            'title'       => sprintf('Weak hash-based randomness in %s::%s()', $shortClassName, $methodName),
            'description' => $description,
            'severity'    => 'critical',
            'suggestion'  => $this->createSecureRandomSuggestion($entityClass, $methodName, 'md5/rand', $reflectionMethod),
            'backtrace'   => [
                'file' => $reflectionMethod->getFileName(),
                'line' => $reflectionMethod->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createSecureRandomSuggestion(
        string $entityClass,
        string $methodName,
        string $insecureFunction,
        \ReflectionMethod $reflectionMethod,
    ): SuggestionInterface {
        $shortClassName = $this->getShortClassName($entityClass);

        $code = "// In {$shortClassName}::{$methodName}():

";
        $code .= "// INSECURE - DO NOT USE:
";

        if ('rand' === $insecureFunction || 'mt_rand' === $insecureFunction) {
            $code .= "// \$token = bin2hex(random_int(0, PHP_INT_MAX)); // Still predictable!
";
            $code .= "// \$token = md5(rand()); // Hashing weak random is still weak!
";
            $code .= "// \$token = uniqid(); // Based on timestamp, predictable!

";
        }

        $code .= "//  SECURE - Cryptographically secure random:

";
        $code .= "// Generate random token (hex string)
";
        $code .= "public function generateToken(): string
";
        $code .= "{
";
        $code .= "    return bin2hex(random_bytes(32)); // 64 character hex string
";
        $code .= "}

";

        $code .= "// Generate random integer
";
        $code .= "public function generateRandomInt(int \$min, int \$max): int
";
        $code .= "{
";
        $code .= "    return random_int(\$min, \$max); // Cryptographically secure
";
        $code .= "}

";

        $code .= "// Generate UUID v4 (Symfony)
";
        $code .= "use Symfony\Component\Uid\Uuid;

";
        $code .= "public function generateUuid(): string
";
        $code .= "{
";
        $code .= "    return Uuid::v4()->toRfc4122(); // Cryptographically secure UUID
";
        $code .= "}

";

        $code .= "// Generate base64 token
";
        $code .= "public function generateBase64Token(int \$length = 32): string
";
        $code .= "{
";
        $code .= "    return base64_encode(random_bytes(\$length));
";
        $code .= '}';

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Replace with cryptographically secure random generator',
            code: $code,
            filePath: $this->getFileLocation($reflectionMethod),
        );
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
}
