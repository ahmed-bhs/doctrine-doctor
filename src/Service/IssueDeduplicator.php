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
        assert(is_iterable($groupedIssues), '$groupedIssues must be iterable');

        foreach ($groupedIssues as $group) {
            $bestIssue = $this->selectBestIssue($group);
            if (null !== $bestIssue) {
                $deduplicatedIssues[] = $bestIssue;
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

        assert(is_iterable($issues), '$issues must be iterable');

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
            return $firstQuery['sql'];
        }

        return '';
    }

    /**
     * Get signature for repeated query issues (N+1, Lazy Loading, Frequent Query).
     */
    private function getRepeatedQuerySignature(string $title, ?string $entityOrTable): ?string
    {
        if (false === preg_match('/(\d+)\s+(?:queries?|executions?)/i', $title, $matches)) {
            return null;
        }

        if (!isset($matches[1]) || null === $entityOrTable) {
            return null;
        }

        return "repeated_query:{$entityOrTable}:{$matches[1]}";
    }

    /**
     * Get signature for table-related issues (Index, ORDER BY, findAll).
     */
    private function getTableRelatedSignature(string $title, ?string $entityOrTable): ?string
    {
        if (null === $entityOrTable) {
            return null;
        }

        if (str_contains($title, 'Index') || str_contains($title, 'index')) {
            return "table_performance:{$entityOrTable}";
        }

        if (str_contains($title, 'ORDER BY') || str_contains($title, 'findAll')) {
            return "table_query:{$entityOrTable}";
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
     */
    private function extractEntityOrTable(string $title, string $description, string $sql): ?string
    {
        // Try entity name first (e.g., "BillLine", "SubscriptionLine")
        if (false !== preg_match('/(?:entity|class|Entity)\s+["\']?([A-Z]\w+)["\']?/i', $title, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        // Pattern: Complex structure extraction (consider using parser)
        if (false !== preg_match('/(?:entity|class|Entity)\s+["\']?([A-Z]\w+)["\']?/i', $description, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        // Try table name (e.g., "bill_line", "time_entry")
        if (false !== preg_match('/(?:table|FROM|JOIN)\s+["`]?(\w+)["`]?/i', $title, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        // Pattern: SQL query structure extraction
        if ('' !== $sql && false !== preg_match('/FROM\s+(\w+)/i', $sql, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
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
     * Select the best issue to keep from a group of similar issues.
     * Priority order (highest to lowest):
     * 1. N+1 Query (root cause of performance issue)
     * 2. Missing Index (infrastructure issue)
     * 3. Lazy Loading (symptom of N+1)
     * 4. Slow Query (general performance)
     * 5. Frequent Query (may be intentional caching)
     * 6. Query Caching Opportunity (optimization suggestion)
     * @param IssueInterface[] $issues
     */
    private function selectBestIssue(array $issues): ?IssueInterface
    {
        if (1 === count($issues)) {
            return $issues[0];
        }

        // Define priority weights for different issue types
        $priorities = [
            'N+1 Query' => 100,
            'Missing Index' => 90,
            'Lazy Loading' => 80,
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

        assert(is_iterable($issues), '$issues must be iterable');

        foreach ($issues as $issue) {
            $title = $issue->getTitle();
            $severity = $this->getSeverityWeight($issue->getSeverity());

            // Find matching priority
            $priority = 0;
            assert(is_iterable($priorities), '$priorities must be iterable');

            foreach ($priorities as $keyword => $weight) {
                if (str_contains($title, $keyword)) {
                    $priority = $weight;
                    break;
                }
            }

            // Select issue with highest priority, then severity
            if ($priority > $bestPriority ||
                ($priority === $bestPriority && $severity > $bestSeverity)) {
                $bestPriority = $priority;
                $bestSeverity = $severity;
                $bestIssue = $issue;
            }
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
