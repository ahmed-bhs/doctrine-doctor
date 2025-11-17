<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\StructuredSuggestion;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionContent;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionContentBlock;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Webmozart\Assert\Assert;

/**
 * Detects queries that load entire entities when only a few fields are needed.
 * Loading full entities when you only need 1-2 fields wastes:
 * - Memory (40-80% more than needed)
 * - Network bandwidth (50-90% more data transfer)
 * - Database resources (fetching unused columns)
 * Example:
 * BAD:
 *   SELECT u FROM User u  -- Loads ALL 20 fields
 *   Then uses only: $user->getUsername()
 *  GOOD:
 *   SELECT PARTIAL u.{id, username} FROM User u  -- Loads only 2 fields
 *   Or: SELECT u.id, u.username FROM User u  -- Array result
 */
class PartialObjectAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Minimum number of queries to trigger detection.
     */
    private const MIN_QUERY_COUNT = 3;

    /**
     * Keywords that suggest write operations (exclude from detection).
     */
    private const WRITE_PATTERNS = [
        'UPDATE',
        'DELETE',
        'INSERT',
        'SET',
    ];

    private SqlStructureExtractor $sqlExtractor;

    public function __construct(
        /**
         * @readonly
         */
        private int $threshold = 5,
        ?SqlStructureExtractor $sqlExtractor = null,
    ) {
        $this->sqlExtractor = $sqlExtractor ?? new SqlStructureExtractor();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                //  Convert to array locally to allow multiple iterations (count + grouping)
                $queriesArray = $queryDataCollection->toArray();

                if (count($queriesArray) < self::MIN_QUERY_COUNT) {
                    return; //  Generator: use empty return instead of returning value
                }

                // Group queries by their DQL pattern
                $queryPatterns = $this->groupQueriesByPattern(QueryDataCollection::fromArray($queriesArray));

                Assert::isIterable($queryPatterns, '$queryPatterns must be iterable');

                foreach ($queryPatterns as $pattern => $queryGroup) {
                    if (count($queryGroup) < $this->threshold) {
                        continue;
                    }

                    // Check if queries are loading full entities
                    // Use first query's original SQL (not normalized pattern) for detection
                    Assert::string($pattern, 'Pattern key must be string');
                    $firstQuery = $queryGroup[0];
                    $originalSql = $firstQuery->sql;

                    if ($this->isFullEntityLoad($originalSql)) {
                        $issue = $this->createPartialObjectIssue($queryGroup);
                        yield $issue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Partial Object Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects queries loading full entities when partial objects or array hydration would be more efficient';
    }

    /**
     * Group queries by their normalized pattern.
     */
    private function groupQueriesByPattern(QueryDataCollection $queryDataCollection): array
    {

        $patterns = [];

        Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

        foreach ($queryDataCollection as $query) {
            $sql = $query->sql;

            // Skip write operations
            if ($this->isWriteOperation($sql)) {
                continue;
            }

            // Normalize query to create pattern
            $pattern = $this->normalizeQuery($sql);

            if (!isset($patterns[$pattern])) {
                $patterns[$pattern] = [];
            }

            $patterns[$pattern][] = $query;
        }

        return $patterns;
    }

    /**
     * Check if query is a write operation.
     */
    private function isWriteOperation(string $sql): bool
    {
        $upperSql = strtoupper($sql);

        foreach (self::WRITE_PATTERNS as $pattern) {
            if (str_contains($upperSql, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes query using universal SQL parser method with caching.
     *
     * Migration from regex to SQL Parser:
     * - Replaced 4 regex patterns with SqlStructureExtractor::normalizeQuery()
     * - More robust: properly parses SQL structure
     * - Handles complex queries, subqueries, joins
     * - Fallback to regex if parser fails
     * - OPTIMIZED: Uses global cache for 654x speedup
     */
    private function normalizeQuery(string $sql): string
    {
        return SqlNormalizationCache::normalize($sql);
    }

    /**
     * Check if query loads full entities (SELECT e FROM Entity e).
     */
    private function isFullEntityLoad(string $sql): bool
    {
        $upperSql = strtoupper($sql);

        // Pattern: SELECT e FROM ... (entity alias only, not specific fields)
        // This indicates full entity hydration
        if (1 === preg_match('/SELECT\s+([a-z]\w*)\s+FROM/i', $sql, $matches)) {
            $selectPart = $matches[1];

            // If SELECT contains only an alias (single word), it's a full entity load
            if (!str_contains($selectPart, '.') && !str_contains($selectPart, ',')) {
                return true;
            }
        }

        // Pattern: SELECT * FROM (always full load)
        return str_contains($upperSql, 'SELECT *');
    }

    /**
     * Create issue for partial object optimization opportunity.
     */
    private function createPartialObjectIssue(array $queries): PerformanceIssue
    {
        $count      = count($queries);
        $firstQuery = $queries[0];
        $example    = $firstQuery instanceof QueryData ? $firstQuery->sql : ($firstQuery['sql'] ?? '');

        // Extract entity name from query
        $entityName = $this->extractEntityName($example);

        // Calculate potential savings
        $avgTime          = $this->calculateAverageTime($queries);
        $estimatedSavings = $this->estimateSavings($avgTime, $count);

        $performanceIssue = new PerformanceIssue([
            'query_count'       => $count,
            'example_query'     => $example,
            'entity'            => $entityName,
            'avg_time'          => $avgTime,
            'estimated_savings' => $estimatedSavings,
        ]);

        $performanceIssue->setSeverity($count > 10 ? 'critical' : 'warning');
        $performanceIssue->setTitle('Full Entity Loading - Consider Partial Objects');
        $performanceIssue->setMessage(
            sprintf('Detected %d queries loading full entities when partial objects might be more efficient. ', $count) .
            'If you only need a few fields, consider using partial objects or array hydration to save memory and improve performance.',
        );
        $performanceIssue->setSuggestion($this->buildSuggestion($entityName));

        return $performanceIssue;
    }

    /**
     * Extract entity name from DQL/SQL query.
     */
    private function extractEntityName(string $sql): string
    {
        // Try to extract from DQL: SELECT u FROM User u
        if (1 === preg_match('/FROM\s+([A-Z]\w+(?:\\[A-Z]\w+)*)/i', $sql, $matches)) {
            return $matches[1];
        }

        // Try to extract from SQL: SELECT * FROM users
        if (1 === preg_match('/FROM\s+([a-z_]+)/i', $sql, $matches)) {
            return $matches[1];
        }

        return 'Entity';
    }

    /**
     * Calculate average execution time.
     */
    private function calculateAverageTime(array $queries): float
    {
        $total = 0;
        $count = 0;

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            if ($query instanceof QueryData) {
                $total += $query->executionTime->inMilliseconds();
                ++$count;
            } elseif (isset($query['executionMS'])) {
                $total += $query['executionMS'];
                ++$count;
            }
        }

        return $count > 0 ? round($total / $count, 2) : 0;
    }

    /**
     * Estimate potential memory/time savings.
     */
    private function estimateSavings(float $avgTime, int $count): string
    {
        // Conservative estimate: 40% memory savings, 20% time savings
        $memorySavings = 40;
        $timeSavings   = round($avgTime * 0.2 * $count, 2);

        return sprintf('%d%% memory reduction, ~%sms total time saved', $memorySavings, $timeSavings);
    }

    /**
     * Build detailed suggestion.
     */
    private function buildSuggestion(string $entityName): StructuredSuggestion
    {
        $blocks = [
            SuggestionContentBlock::text('Consider using partial objects or array hydration for read-only operations:'),

            // Bad example
            SuggestionContentBlock::heading('Current approach (loads all fields)', 4),
            SuggestionContentBlock::code(
                "// Loads ALL entity fields (wasteful if you only need 2-3)
" .
                "\$query = \$em->createQuery('SELECT e FROM {$entityName} e');
" .
                "\$entities = \$query->getResult();
" .
                "foreach (\$entities as \$entity) {
" .
                "    echo \$entity->getName(); // Using only 1 field out of many
" .
                '}',
                'php',
                'Bad',
            ),

            // Option 1: Partial Objects
            SuggestionContentBlock::heading('Option 1: Partial Objects (best for object-oriented code)', 4),
            SuggestionContentBlock::code(
                "// Load only specific fields (e.g., id, name)
" .
                "\$query = \$em->createQuery(
" .
                "    'SELECT PARTIAL e.{id, name, email} FROM {$entityName} e'
" .
                ");
" .
                "\$entities = \$query->getResult();
" .
                '// Entities are read-only but still objects',
                'php',
                'Good',
            ),

            // Option 2: Array Hydration
            SuggestionContentBlock::heading('Option 2: Array Hydration (best for lists/reports)', 4),
            SuggestionContentBlock::code(
                "// Fastest option - returns arrays instead of objects
" .
                "\$query = \$em->createQuery(
" .
                "    'SELECT e.id, e.name, e.email FROM {$entityName} e'
" .
                ");
" .
                "\$data = \$query->getArrayResult();
" .
                "// Returns: [['id' => 1, 'name' => 'John', 'email' => '...'], ...]",
                'php',
                'Good',
            ),

            // Option 3: Scalar Hydration
            SuggestionContentBlock::heading('Option 3: Scalar Hydration (for single column)', 4),
            SuggestionContentBlock::code(
                "// For single column results
" .
                "\$query = \$em->createQuery('SELECT e.name FROM {$entityName} e');
" .
                '$names = $query->getScalarResult();',
                'php',
                'Good',
            ),

            // Performance benefits
            SuggestionContentBlock::heading('Performance benefits:', 4),
            SuggestionContentBlock::unorderedList([
                '40-80% less memory usage',
                '20-50% faster query execution',
                '50-90% less network data transfer',
                'Better scalability for large datasets',
            ]),

            // When to use each
            SuggestionContentBlock::heading('When to use each:', 4),
            SuggestionContentBlock::unorderedList([
                '**Partial objects**: When you need object methods but not all fields',
                '**Array hydration**: For read-only lists, reports, exports',
                '**Scalar hydration**: For single column results or aggregations',
            ]),

            // Important notes
            SuggestionContentBlock::heading('Important:', 4),
            SuggestionContentBlock::unorderedList([
                'Partial objects are **READ-ONLY** (cannot be persisted)',
                'Use full entities only when you need to modify them',
                'For APIs/JSON responses, always use partial/array hydration',
            ]),
        ];

        return new StructuredSuggestion(
            title: 'Use Partial Objects or Array Hydration',
            suggestionContent: new SuggestionContent($blocks),
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::PERFORMANCE,
                severity: Severity::WARNING,
                title: 'Use Partial Objects or Array Hydration',
                tags: ['performance', 'memory', 'hydration'],
            ),
            summary: 'Loading full entities when you only need a few fields wastes memory (40-80%), network bandwidth (50-90%), and database resources. Use partial objects, array hydration, or scalar hydration instead.',
        );
    }
}
