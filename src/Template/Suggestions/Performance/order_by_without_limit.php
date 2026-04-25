<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $orderByClause
 * @var mixed $originalQuery
 * @var mixed $context
 */
['order_by_clause' => $orderByClause, 'original_query' => $originalQuery, 'query_context' => $queryContext] = $context;
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

$isBoundedArrayResult = 'array_result' === $queryContext
    && 1 === preg_match('/\bWHERE\b/i', (string) $originalQuery);

ob_start();
?>

<div class="suggestion-header">
    <h4>ORDER BY without LIMIT</h4>
</div>

<div class="suggestion-content">
<?php if ($isBoundedArrayResult): ?>
    <div class="alert alert-info">
        This query filters by a foreign key (WHERE clause present), so the result set is bounded today.
        However, ORDER BY without LIMIT means the database must sort <strong>all matching rows</strong> in memory.
        As the related data grows in production, this will become increasingly expensive.
    </div>

    <h4>Options</h4>
    <div class="query-item">
        <pre><code class="language-php">// Option 1: Add LIMIT if you only need the most recent N entries
$qb->orderBy('h.occurredAt', 'ASC')
   ->setMaxResults(100);

// Option 2: If you always need all entries, add a database index
// on the ORDER BY column to make sorting cheap:
// #[ORM\Index(columns: ['occurred_at'])]

// Option 3: Accept the behaviour if the collection is guaranteed
// to stay small (e.g. audit trail per entity), and suppress the alert:
// doctrine_doctor.analyzers.order_by_without_limit.min_execution_time_ms: 0</code></pre>
    </div>

    <p><strong>Why it matters:</strong> A bounded query with 10 rows today can have 10,000 rows in two years.
    Adding an index on <code><?= $e((string) $orderByClause) ?></code> costs nothing and future-proofs the sort.</p>
<?php else: ?>
    <div class="alert alert-warning">
        Query uses ORDER BY without LIMIT — sorting large datasets is expensive and may not be needed.
    </div>

    <h4>Solution: Add LIMIT or remove ORDER BY</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: Sorts entire table (slow)
$qb->select('u')
   ->from(User::class, 'u')
   ->orderBy('u.createdAt', 'DESC');  // Sorts ALL users!

// Option 1: Add LIMIT (most common)
$qb->select('u')
   ->from(User::class, 'u')
   ->orderBy('u.createdAt', 'DESC')
   ->setMaxResults(10);  // Only need top 10

// Option 2: Remove ORDER BY if not needed
$qb->select('u')
   ->from(User::class, 'u');  // No sorting needed</code></pre>
    </div>

    <p><strong>Performance:</strong> Sorting 1M rows without LIMIT uses significant CPU/memory. Add LIMIT or remove ORDER BY.</p>
<?php endif; ?>

    <p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#first-and-max-result-items-dql-query-only" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM First and Max Result Items</a></p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => $isBoundedArrayResult
        ? 'Result set is bounded today but will grow — add an index on the ORDER BY column or add LIMIT'
        : 'Add LIMIT when using ORDER BY, or remove ORDER BY if not needed',
];
