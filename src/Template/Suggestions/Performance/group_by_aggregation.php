<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entity
 * @var mixed $relation
 * @var mixed $queryCount
 * @var mixed $context
 */
['entity' => $entity, 'relation' => $relation, 'query_count' => $queryCount] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Suggested Fix: GROUP BY Aggregation Query</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>N+1 Query with Aggregation Detected</strong><br>
        Detected <strong><?php echo $queryCount; ?> queries</strong> loading <code><?php echo $e($relation); ?></code> for counting or aggregation.
        Use a single query with GROUP BY to aggregate data efficiently without JOIN duplication.
    </div>

    <h4>Problem: Loading Relations Just for Counting</h4>
    <div class="query-item">
        <pre><code class="language-php">// BAD: Loading all data then counting
$entities = $repository->findAll();
Assert::isIterable($entities, '$entities must be iterable');

$response = [];
foreach ($entities as $entity) {
    $response[] = [
        'title' => $entity->getTitle(),
        'count' => $entity->get<?php echo ucfirst($relation); ?>()->count(), // N+1!
    ];
}
// Result: <?php echo $queryCount; ?> queries (even with EXTRA_LAZY)</code></pre>
    </div>

    <h4>Solution: Single Query with GROUP BY + COUNT</h4>
    <div class="query-item">
        <pre><code class="language-php">// BEST: Aggregate in database with GROUP BY
$query = $entityManager->createQuery('
    SELECT e.id, e.title, COUNT(r.id) AS <?php echo lcfirst($relation); ?>Count
    FROM App\\Entity\\<?php echo $e($entity); ?> e
    LEFT JOIN e.<?php echo $e($relation); ?> r
    GROUP BY e.id
');

$results = $query->getResult();
Assert::isIterable($results, '$results must be iterable');

$response = [];
foreach ($results as $row) {
    $response[] = [
        'title' => $row['title'],
        'count' => (int) $row['<?php echo lcfirst($relation); ?>Count'], // From aggregation!
    ];
}
// Result: 1 query total!</code></pre>
    </div>

    <h4>Solution 2: Query Builder (Repository Method)</h4>
    <div class="query-item">
        <pre><code class="language-php">// In <?php echo $e($entity); ?>Repository
/**
 * @return array<int, array{id: int, title: string, <?php echo lcfirst($relation); ?>Count: int}>
 */
public function findAllWith<?php echo ucfirst($relation); ?>Count(): array
{
    return $this->createQueryBuilder('e')
        ->select('e.id', 'e.title', 'COUNT(r.id) AS <?php echo lcfirst($relation); ?>Count')
        ->leftJoin('e.<?php echo $e($relation); ?>', 'r')
        ->groupBy('e.id')
        ->getQuery()
        ->getResult();
}

// Usage:
$results = $repository->findAllWith<?php echo ucfirst($relation); ?>Count();
// Result: 1 query with aggregation</code></pre>
    </div>

    <h4>Solution 3: Hybrid - Objects + Aggregation</h4>
    <div class="query-item">
        <pre><code class="language-php">// If you need full entity objects + count
$query = $entityManager->createQuery('
    SELECT e, COUNT(r.id) AS HIDDEN <?php echo lcfirst($relation); ?>Count
    FROM App\\Entity\\<?php echo $e($entity); ?> e
    LEFT JOIN e.<?php echo $e($relation); ?> r
    GROUP BY e.id
');

$results = $query->getResult();
Assert::isIterable($results, '$results must be iterable');

foreach ($results as $row) {
    $entity = $row[0]; // Full <?php echo $e($entity); ?> object
    $count = $row['<?php echo lcfirst($relation); ?>Count']; // Aggregated count

    echo $entity->getTitle() . ': ' . $count;
}
// Result: 1 query, full objects + counts!</code></pre>
    </div>

    <h4>Advanced: Multiple Aggregations</h4>
    <div class="query-item">
        <pre><code class="language-php">// Aggregate multiple metrics in one query
$query = $entityManager->createQuery('
    SELECT
        e.id,
        e.title,
        COUNT(r.id) AS <?php echo lcfirst($relation); ?>Count,
        SUM(CASE WHEN r.status = :published THEN 1 ELSE 0 END) AS publishedCount,
        MAX(r.createdAt) AS lastCreated
    FROM App\\Entity\\<?php echo $e($entity); ?> e
    LEFT JOIN e.<?php echo $e($relation); ?> r
    GROUP BY e.id
')->setParameter('published', 'published');

$results = $query->getResult();
// Result: 1 query with multiple aggregations!</code></pre>
    </div>

    <h4>⚖️ Trade-offs: GROUP BY Aggregation</h4>
    <div class="alert alert-warning">
        <strong>Pros:</strong>
        <ul>
            <li><strong>Single query</strong>: All data aggregated in one database call</li>
            <li><strong>No data duplication</strong>: Unlike JOIN FETCH, rows aren't duplicated</li>
            <li><strong>Database-level aggregation</strong>: Fast and efficient</li>
            <li><strong>Flexible</strong>: Can add SUM, AVG, MAX, MIN, etc.</li>
        </ul>
        <strong>Cons:</strong>
        <ul>
            <li><strong>Scalar results</strong>: Returns arrays, not hydrated objects (unless hybrid)</li>
            <li><strong>Custom queries</strong>: Requires writing DQL/QueryBuilder code</li>
            <li><strong>Not ORM-managed</strong>: Aggregated values aren't entity properties</li>
            <li><strong>Complex with many fields</strong>: Must list all non-aggregated fields in GROUP BY</li>
        </ul>
    </div>

    <h4>When to Use GROUP BY Aggregation</h4>
    <ul>
        <li><strong>Counting/summing</strong>: When you only need counts, not full objects</li>
        <li><strong>API responses</strong>: Returning aggregated data as JSON</li>
        <li><strong>Reporting/dashboards</strong>: Statistics and summaries</li>
        <li><strong>Avoiding duplication</strong>: When JOIN FETCH would duplicate rows</li>
    </ul>

    <h4>Best Practices</h4>
    <ul>
        <li>Use LEFT JOIN for optional relations (NULL-safe counts)</li>
        <li>Always include entity ID in GROUP BY</li>
        <li>Use HIDDEN for aggregations you don't want in results</li>
        <li>Index columns used in GROUP BY for performance</li>
        <li>Consider pagination with aggregations (LIMIT + GROUP BY)</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li><strong>Current:</strong> <?php echo $queryCount; ?> queries</li>
            <li><strong>With GROUP BY:</strong> 1 query</li>
            <li><strong>Query reduction:</strong> <?php echo round((($queryCount - 1) / $queryCount) * 100); ?>%</li>
            <li><strong>No data duplication:</strong> Unlike JOIN FETCH (cleaner result set)</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#grouping" target="_blank" class="doc-link">
            📖 Doctrine DQL GROUP BY Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Use GROUP BY with COUNT() to aggregate %s data in a single query without JOIN duplication',
        $relation,
    ),
];
