<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * Detects entity collections that are not properly initialized in constructors.
 * This analyzer checks entity metadata to find OneToMany and ManyToMany relations
 * that should be initialized with ArrayCollection but might not be.
 */
class CollectionInitializationAnalyzer implements AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param QueryDataCollection $queryDataCollection - Not heavily used, this analyzer focuses on entity metadata
     * @return IssueCollection<CodeQualityIssue>
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

                    assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata);

                        assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    // Log error safely - avoid getMessage() which might contain non-stringable objects
                    $this->logger?->error('CollectionInitializationAnalyzer error', [
                        'exception' => $throwable::class,
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Collection Initialization Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects entity collections that are not properly initialized in constructors';
    }

    /**
     * @return array<CodeQualityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        // Check all associations
        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            // Only check collection types (OneToMany and ManyToMany)
            if (!$this->isCollectionAssociation($associationMapping)) {
                continue;
            }

            // Check if entity has a constructor
            $reflectionClass = $classMetadata->getReflectionClass();

            if (!$reflectionClass->hasMethod('__construct')) {
                $issues[] = $this->createMissingConstructorIssue($entityClass, $fieldName, $associationMapping);
                continue;
            }

            // Check if collection is initialized in constructor
            $constructor = $reflectionClass->getMethod('__construct');

            if (!$this->isCollectionInitializedInConstructor($constructor, $fieldName)) {
                $issues[] = $this->createUninitializedCollectionIssue($entityClass, $fieldName, $associationMapping, $constructor);
            }
        }

        return $issues;
    }

    private function isCollectionAssociation(array|object $mapping): bool
    {
        // For Doctrine ORM 3.x/4.x: check class name
        if (is_object($mapping)) {
            $className = get_class($mapping);

            return str_contains($className, 'OneToManyAssociationMapping')
                || str_contains($className, 'ManyToManyAssociationMapping')
                || str_contains($className, 'ManyToManyOwningSideMapping')
                || str_contains($className, 'ManyToManyInverseSideMapping');
        }

        // For Doctrine ORM 2.x: check 'type' key
        $type = MappingHelper::getInt($mapping, 'type');

        return ClassMetadata::ONE_TO_MANY === $type || ClassMetadata::MANY_TO_MANY === $type;
    }

    private function isCollectionInitializedInConstructor(\ReflectionMethod $reflectionMethod, string $fieldName): bool
    {
        try {
            $filename = $reflectionMethod->getFileName();
            $startLine = $reflectionMethod->getStartLine();
            $constructorCode = $this->extractConstructorCode($reflectionMethod);
            if (null === $constructorCode) {
                return false;
            }

            // Remove comments to avoid false positives
            // Remove single-line comments (// ...)
            $constructorCode = preg_replace('/\/\/.*$/m', '', $constructorCode) ?? $constructorCode;
            // Remove multi-line comments (/* ... */)
            $constructorCode = preg_replace('/\/\*.*?\*\//s', '', $constructorCode) ?? $constructorCode;

            // Check if collection is initialized
            // Look for patterns like: $this->fieldName = new ArrayCollection()

            $escapedFieldName = preg_quote($fieldName, '/');

            if ('' === $escapedFieldName) {
                $this->logger?->warning('CollectionInitializationAnalyzer: preg_quote failed', [
                    'fieldName' => $fieldName,
                    'escapedType' => get_debug_type($escapedFieldName),
                    'file' => $filename,
                    'startLine' => $startLine,
                ]);

                return false;
            }

            // Build patterns safely using single quotes to avoid $this interpolation
            $patterns = [
                '/\$this->' . $escapedFieldName . '\s*=\s*new\s+ArrayCollection/',
                '/\$this->' . $escapedFieldName . '\s*=\s*\[\]/', // PHP array initialization
            ];

            assert(is_iterable($patterns), '$patterns must be iterable');

            foreach ($patterns as $patternIndex => $pattern) {
                try {
                    // Wrap each preg_match to catch PCRE errors (backtrack limit, etc.)
                    $result = preg_match($pattern, $constructorCode);

                    if (1 === $result) {
                        return true;
                    }

                    // Check for preg errors
                    $pregError = preg_last_error();

                    if (PREG_NO_ERROR !== $pregError) {
                        $errorMessages = [
                            PREG_INTERNAL_ERROR        => 'PREG_INTERNAL_ERROR',
                            PREG_BACKTRACK_LIMIT_ERROR => 'PREG_BACKTRACK_LIMIT_ERROR',
                            PREG_RECURSION_LIMIT_ERROR => 'PREG_RECURSION_LIMIT_ERROR',
                            PREG_BAD_UTF8_ERROR        => 'PREG_BAD_UTF8_ERROR',
                            PREG_BAD_UTF8_OFFSET_ERROR => 'PREG_BAD_UTF8_OFFSET_ERROR',
                        ];
                        $errorName = $errorMessages[$pregError] ?? sprintf('UNKNOWN_ERROR(%s)', $pregError);

                        $this->logger?->warning('CollectionInitializationAnalyzer: PCRE error', [
                            'error' => $errorName,
                            'patternIndex' => $patternIndex,
                            'file' => $filename,
                            'startLine' => $startLine,
                        ]);
                        continue;
                    }
                } catch (\Throwable $e) {
                    $this->logger?->warning('CollectionInitializationAnalyzer: Regex exception', [
                        'exception' => $e::class,
                        'patternIndex' => $patternIndex,
                        'file' => $filename,
                        'startLine' => $startLine,
                    ]);
                    continue;
                }
            }

            return false;
        } catch (\Throwable $throwable) {
            // Catch all errors in this method
            $this->logger?->error('CollectionInitializationAnalyzer: Unexpected error', [
                'exception' => $throwable::class,
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ]);

            return false;
        }
    }

    private function createMissingConstructorIssue(string $entityClass, string $fieldName, array|object $mapping): CodeQualityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $targetEntity   = $this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown');

        return new CodeQualityIssue([
            'title'       => 'Missing constructor for collection initialization in ' . $shortClassName,
            'description' => sprintf(
                'Entity "%s" has a collection property "$%s" (relation to %s) but no constructor. ' .
                'Collections must be initialized to prevent null pointer exceptions. ' .
                'Without initialization, accessing the collection will cause a fatal error.',
                $shortClassName,
                $fieldName,
                $targetEntity,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->suggestionFactory->createCollectionInitialization(
                entityClass: $this->getShortClassName($entityClass),
                fieldName: $fieldName,
                hasConstructor: false,
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createUninitializedCollectionIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        \ReflectionMethod $reflectionMethod,
    ): CodeQualityIssue {
        $shortClassName = $this->getShortClassName($entityClass);
        $targetEntity   = $this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown');

        return new CodeQualityIssue([
            'title'       => sprintf('Uninitialized collection in %s::$%s', $shortClassName, $fieldName),
            'description' => sprintf(
                'Entity "%s" has a collection property "$%s" (relation to %s) that is not initialized in the constructor. ' .
                'This will cause "Call to a member function on null" errors when trying to add or access items. ' .
                'Collections should always be initialized with "new ArrayCollection()" in the constructor.',
                $shortClassName,
                $fieldName,
                $targetEntity,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->suggestionFactory->createCollectionInitialization(
                entityClass: $this->getShortClassName($entityClass),
                fieldName: $fieldName,
                hasConstructor: true,
                backtrace: sprintf('%s:%d', $reflectionMethod->getFileName() ?: 'unknown', $reflectionMethod->getStartLine() ?: 0),
            ),
            'backtrace' => [
                'file' => $reflectionMethod->getFileName() ?: 'unknown',
                'line' => $reflectionMethod->getStartLine() ?: 0,
            ],
            'queries' => [],
        ]);
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    private function extractConstructorCode(\ReflectionMethod $reflectionMethod): ?string
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

        $lineCount = $endLine - $startLine + 1;
        if ($lineCount > 500) {
            $this->logger?->warning('CollectionInitializationAnalyzer: Constructor too large - skipping', [
                'lines' => $lineCount,
                'file' => $filename,
                'startLine' => $startLine,
            ]);

            return null;
        }

        $constructorCode = implode('', array_slice($source, $startLine - 1, $lineCount));
        if (strlen($constructorCode) > 50000) {
            $this->logger?->warning('CollectionInitializationAnalyzer: Constructor code too large - skipping', [
                'bytes' => strlen($constructorCode),
                'file' => $filename,
                'startLine' => $startLine,
            ]);

            return null;
        }

        return $constructorCode;
    }
}
