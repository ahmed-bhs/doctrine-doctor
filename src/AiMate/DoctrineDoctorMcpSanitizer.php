<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\AiMate;

use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueCategory;
use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use Throwable;

final readonly class DoctrineDoctorMcpSanitizer
{
    private const int MAX_QUERIES_PER_ISSUE = 3;

    public function __construct(private TraceSanitizer $traceSanitizer)
    {
    }

    /**
     * @param list<IssueInterface> $issues
     * @return list<array<string, mixed>>
     */
    public function sanitizeIssues(
        array $issues,
        ?string $category = null,
        ?string $severity = null,
        int $limit = 20,
        bool $includeQueries = false,
    ): array {
        $filtered = array_filter(
            $issues,
            static function (IssueInterface $issue) use ($category, $severity): bool {
                if ($category !== null && $issue->getCategory()->value !== $category) {
                    return false;
                }

                if ($severity !== null && $issue->getSeverity()->value !== $severity) {
                    return false;
                }

                return true;
            },
        );

        return array_map(
            fn (IssueInterface $issue): array => $this->sanitizeIssue($issue, $includeQueries),
            array_slice(array_values($filtered), 0, max(1, $limit)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitizeIssue(IssueInterface $issue, bool $includeQueries = false): array
    {
        $payload = [
            'type' => $issue->getType(),
            'title' => $issue->getTitle(),
            'description' => $this->normalizeText($issue->getDescription()),
            'severity' => $issue->getSeverity()->value,
            'category' => $issue->getCategory()->value,
            'hint' => $this->sanitizeHint($issue->getSuggestion()),
            'trace' => $this->traceSanitizer->sanitize($issue->getBacktrace()),
            'query_count' => count($issue->getQueries()),
            'duplicated_issue_count' => count($issue->getDuplicatedIssues()),
        ];

        if ($includeQueries) {
            $payload['queries'] = $this->sanitizeQueries($issue->getQueries());
        }

        return $payload;
    }

    private function sanitizeHint(?SuggestionInterface $suggestion): ?array
    {
        if (!$suggestion instanceof SuggestionInterface) {
            return null;
        }

        try {
            $summary = $this->normalizeText($suggestion->getDescription());
        } catch (Throwable) {
            $summary = '';
        }

        if ($summary === '') {
            try {
                $summary = $this->normalizeText(strip_tags($suggestion->getCode()));
            } catch (Throwable) {
                $summary = '';
            }
        }

        return [
            'title' => $suggestion->getMetadata()->title,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<mixed> $queries
     * @return list<array{sql: string, execution_ms: float, row_count?: int|null}>
     */
    private function sanitizeQueries(array $queries): array
    {
        $result = [];

        foreach (array_slice($queries, 0, self::MAX_QUERIES_PER_ISSUE) as $query) {
            $sql = '';
            $executionMs = 0.0;
            $rowCount = null;

            if ($query instanceof QueryData) {
                $sql = $query->sql;
                $executionMs = $query->executionTime->inMilliseconds();
                $rowCount = $query->rowCount;
            } elseif (is_array($query)) {
                $sql = is_string($query['sql'] ?? null) ? $query['sql'] : '';

                $rawExecution = $query['executionMS'] ?? 0.0;
                $executionMs = is_int($rawExecution) || is_float($rawExecution)
                    ? (float) $rawExecution * 1000.0
                    : 0.0;

                $rowCount = is_int($query['rowCount'] ?? null)
                    ? $query['rowCount']
                    : (is_int($query['row_count'] ?? null) ? $query['row_count'] : null);
            }

            if ($sql === '') {
                continue;
            }

            $sanitized = [
                'sql' => $this->sanitizeSql($sql),
                'execution_ms' => round($executionMs, 3),
            ];

            if ($rowCount !== null) {
                $sanitized['row_count'] = $rowCount;
            }

            $result[] = $sanitized;
        }

        return $result;
    }

    private function sanitizeSql(string $sql): string
    {
        $normalized = SqlNormalizationCache::normalize($sql);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? $normalized;

        if (mb_strlen($normalized) <= 240) {
            return $normalized;
        }

        return mb_substr($normalized, 0, 237) . '...';
    }

    private function normalizeText(string $text): string
    {
        $decoded = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/', ' ', trim($decoded)) ?? trim($decoded);

        return $normalized;
    }
}

