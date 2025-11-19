<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $orderByClause
 * @var mixed $originalQuery
 * @var mixed $context
 */
['order_by_clause' => $orderByClause, 'original_query' => $originalQuery] = $context;
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>ORDER BY without LIMIT</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Query uses ORDER BY without LIMIT - sorting large datasets is expensive and may not be needed.
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
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add LIMIT when using ORDER BY, or remove ORDER BY if not needed',
];
