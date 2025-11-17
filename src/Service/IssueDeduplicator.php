<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Service;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Webmozart\Assert\Assert;

/**
 * Deduplicates issues to avoid showing the same problem multiple times.
 * Rules:
 * - If N+1 detected, suppress "Frequent Query" and "Lazy Loading" for same query pattern
 * - If Missing Index detected, suppress less severe issues on same table
 * - Merge similar issues into one with higher severity
 * - Suppress low-impact issues that add noise
 */
final class IssueDeduplicator
{
    /**
     * Remove duplicate and redundant issues.
     */
    public function deduplicate(IssueCollection $issues): IssueCollection
    {
        // Step 1: Group issues by query signature or entity/table
        $groupedIssues = $this->groupIssues($issues);

        // Step 2: Apply deduplication rules within each group
        $deduplicatedIssues = [];
        Assert::isIterable($groupedIssues, '$groupedIssues must be iterable');

        foreach ($groupedIssues as $group) {
            $result = $this->selectBestIssueWithDuplicates($group);
            if (null !== $result) {
                $deduplicatedIssues[] = $result;
            }
        }

        return IssueCollection::fromArray($deduplicatedIssues);
    }

    /**
     * Group issues by their root cause (query, table, entity).
     * @return array<string, IssueInterface[]>
     */
    private function groupIssues(IssueCollection $issues): array
    {
        $groups = [];

        Assert::isIterable($issues, '$issues must be iterable');

        foreach ($issues as $issue) {
            $signature = $this->getIssueSignature($issue);

            if (!isset($groups[$signature])) {
                $groups[$signature] = [];
            }

            $groups[$signature][] = $issue;
        }

        return $groups;
    }

    /**
     * Generate a signature for grouping related issues.
     * Issues with the same signature are candidates for deduplication.
     */
    private function getIssueSignature(IssueInterface $issue): string
    {
        $title = $issue->getTitle();
        $description = $issue->getDescription();
        $sql = $this->extractSqlFromIssue($issue);
        $entityOrTable = $this->extractEntityOrTable($title, $description, $sql);

        // Try specific signature strategies in order of priority
        $signature = $this->getRepeatedQuerySignature($title, $entityOrTable);
        if (null !== $signature) {
            return $signature;
        }

        $signature = $this->getTableRelatedSignature($title, $entityOrTable);
        if (null !== $signature) {
            return $signature;
        }

        $signature = $this->getSqlBasedSignature($sql);
        if (null !== $signature) {
            return $signature;
        }

        // Default: use title + entity as signature
        return 'generic:' . md5($title . ':' . ($entityOrTable ?? ''));
    }

    /**
     * Extract SQL from issue's queries array.
     * Supports both QueryData objects and legacy array format.
     */
    private function extractSqlFromIssue(IssueInterface $issue): string
    {
        $queries = $issue->getQueries();

        if (0 === count($queries)) {
            return '';
        }

        $firstQuery = $queries[0];

        // Handle object with public sql property (QueryData or similar)
        if (is_object($firstQuery) && property_exists($firstQuery, 'sql')) {
            /** @var object{sql: string} $firstQuery */
            return $firstQuery->sql;
        }

        // Handle array format
        if (is_array($firstQuery) && isset($firstQuery['sql'])) {
            assert(is_string($firstQuery['sql']), 'SQL must be a string');
            return $firstQuery['sql'];
        }

        return '';
    }

    /**
     * Get signature for repeated query issues (N+1, Lazy Loading, Frequent Query).
     */
    private function getRepeatedQuerySignature(string $title, ?string $entityOrTable): ?string
    {
        // Match patterns like:
        // - "N+1 Query Detected: 35 queries"
        // - "Frequent Query Executed 35 Times"
        // - "Lazy Loading in Loop: 35 queries"
        if (false === preg_match('/(\d+)\s+(?:queries?|executions?|times?|rows?)/i', $title, $matches)) {
            return null;
        }

        if (!isset($matches[1]) || null === $entityOrTable) {
            return null;
        }

        // Normalize entity/table name to lowercase and remove underscores for consistent grouping
        // Examples: "BillLine" -> "billline", "bill_line" -> "billline"
        $normalizedEntity = str_replace('_', '', strtolower($entityOrTable));

        return "repeated_query:{$normalizedEntity}:{$matches[1]}";
    }

    /**
     * Get signature for table-related issues (Index, ORDER BY, findAll).
     */
    private function getTableRelatedSignature(string $title, ?string $entityOrTable): ?string
    {
        if (null === $entityOrTable) {
            return null;
        }

        // Normalize entity/table name to lowercase and remove underscores for consistent grouping
        $normalizedEntity = str_replace('_', '', strtolower($entityOrTable));

        if (str_contains($title, 'Index') || str_contains($title, 'index')) {
            return "table_performance:{$normalizedEntity}";
        }

        if (str_contains($title, 'ORDER BY') || str_contains($title, 'findAll')) {
            return "table_query:{$normalizedEntity}";
        }

        return null;
    }

    /**
     * Get signature based on SQL query normalization.
     */
    private function getSqlBasedSignature(string $sql): ?string
    {
        if ('' === $sql) {
            return null;
        }

        $normalizedSql = $this->normalizeSql($sql);

        return 'sql:' . md5($normalizedSql);
    }

    /**
     * Extract entity or table name from issue information.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function extractEntityOrTable(string $title, string $description, string $sql): ?string
    {
        // Try entity name first (e.g., "BillLine", "SubscriptionLine")
        if (false !== preg_match('/(?:entity|class|Entity)\s+["\']?([A-Z]\w+)["\']?/i', $title, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        if (false !== preg_match('/(?:entity|class|Entity)\s+["\']?([A-Z]\w+)["\']?/i', $description, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        // Try table name in title (e.g., "table 'categories'", "on categories")
        if (false !== preg_match('/(?:table|FROM|JOIN|on)\s+["\']?(\w+)["\']?/i', $title, $matches)) {
            if (isset($matches[1]) && !in_array(strtolower($matches[1]), ['table', 'from', 'join', 'on', 'static'], true)) {
                return $matches[1];
            }
        }

        // Try table name in description
        if (false !== preg_match('/(?:table|FROM|JOIN|on)\s+["\']?(\w+)["\']?/i', $description, $matches)) {
            if (isset($matches[1]) && !in_array(strtolower($matches[1]), ['table', 'from', 'join', 'on', 'static'], true)) {
                return $matches[1];
            }
        }

        // Extract from SQL - try to get the main table
        if ('' !== $sql) {
            // Try FROM clause first
            if (false !== preg_match('/FROM\s+(\w+)/i', $sql, $matches)) {
                if (isset($matches[1])) {
                    return $matches[1];
                }
            }

            // Try WHERE clause for table reference (e.g., "WHERE T0.ID = ?")
            if (false !== preg_match('/WHERE\s+T\d+\.ID\s*=.*?FROM\s+(\w+)/is', $sql, $matches)) {
                if (isset($matches[1])) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * Normalize SQL query for comparison.
     */
    private function normalizeSql(string $sql): string
    {
        // Remove parameters and literals
        $normalized = preg_replace('/\?|\d+|\'[^\']*\'/i', '?', $sql);
        if (null === $normalized) {
            $normalized = $sql;
        }

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        if (null === $normalized) {
            return strtolower(trim($sql));
        }

        // Convert to lowercase
        return strtolower(trim($normalized));
    }

    /**
     * Select the best issue to keep from a group of similar issues and attach duplicates.
     * Priority order (highest to lowest):
     * 1. N+1 Query (root cause of performance issue)
     * 2. Missing Index (infrastructure issue)
     * 3. Lazy Loading (symptom of N+1)
     * 4. Slow Query (general performance)
     * 5. Frequent Query (may be intentional caching)
     * 6. Query Caching Opportunity (optimization suggestion)
     * @param IssueInterface[] $issues
     */
    private function selectBestIssueWithDuplicates(array $issues): ?IssueInterface
    {
        if (1 === count($issues)) {
            return $issues[0];
        }

        // Define priority weights for different issue types
        $priorities = [
            'N+1 Query' => 100,
            'Missing Index' => 90,
            'Lazy Loading' => 80,
            'Too Many JOINs' => 75,  // JoinOptimizationAnalyzer - technical SQL optimization
            'Excessive Eager Loading' => 72,  // EagerLoadingAnalyzer - performance/cartesian product risk
            'Slow Query' => 70,
            'Unused JOIN' => 60,
            'Frequent Query' => 50,
            'Query Caching' => 40,
            'ORDER BY without LIMIT' => 30,
            'findAll()' => 20,
        ];

        $bestIssue = null;
        $bestPriority = -1;
        $bestSeverity = -1;
        $duplicates = [];

        Assert::isIterable($issues, '$issues must be iterable');

        foreach ($issues as $issue) {
            $title = $issue->getTitle();
            $severity = $this->getSeverityWeight($issue->getSeverity());

            // Find matching priority
            $priority = 0;
            Assert::isIterable($priorities, '$priorities must be iterable');

            foreach ($priorities as $keyword => $weight) {
                if (str_contains($title, $keyword)) {
                    $priority = $weight;
                    break;
                }
            }

            // Select issue with highest priority, then severity
            if ($priority > $bestPriority ||
                ($priority === $bestPriority && $severity > $bestSeverity)) {
                // Current best becomes a duplicate if it exists
                if (null !== $bestIssue) {
                    $duplicates[] = $bestIssue;
                }
                $bestPriority = $priority;
                $bestSeverity = $severity;
                $bestIssue = $issue;
            } else {
                // This issue is lower priority, mark it as duplicate
                $duplicates[] = $issue;
            }
        }

        // Attach duplicates to the best issue
        if (null !== $bestIssue && count($duplicates) > 0) {
            $bestIssue->setDuplicatedIssues($duplicates);
        }

        return $bestIssue;
    }

    /**
     * Convert severity enum to numeric weight for comparison.
     */
    private function getSeverityWeight(Severity $severity): int
    {
        return match ($severity) {
            Severity::CRITICAL => 3,
            Severity::WARNING => 2,
            Severity::INFO => 1,
        };
    }
}
