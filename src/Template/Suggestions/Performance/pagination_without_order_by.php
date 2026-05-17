<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$originalQuery = $context['original_query'] ?? '';
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Pagination without ORDER BY</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Using LIMIT or OFFSET without ORDER BY produces an undefined row order.
        Two identical executions can return different rows. Two consecutive pages can show duplicates or skip rows entirely.
    </div>

    <h4>Original query</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= $e((string) $originalQuery) ?></code></pre>
    </div>

    <h4>Fix: add a deterministic ORDER BY</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: unstable pagination
$qb-&gt;select('u')
   -&gt;from(User::class, 'u')
   -&gt;setFirstResult(20)
   -&gt;setMaxResults(20);

// After: deterministic ordering on a unique indexed column (typically the PK)
$qb-&gt;select('u')
   -&gt;from(User::class, 'u')
   -&gt;orderBy('u.id', 'ASC')
   -&gt;setFirstResult(20)
   -&gt;setMaxResults(20);

// If sorting by a non-unique column, add a tiebreaker on the PK
$qb-&gt;orderBy('u.createdAt', 'DESC')
   -&gt;addOrderBy('u.id', 'DESC');</code></pre>
    </div>

    <h4>Why a tiebreaker matters</h4>
    <p>If you order by a column that contains duplicate values (e.g. <code>createdAt</code> at second granularity),
    the relative order of tied rows is undefined. Always add the primary key as a secondary ORDER BY to make pagination
    fully deterministic.</p>

    <p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/pagination.html" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Pagination</a></p>
</div>

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Add a deterministic ORDER BY (with PK tiebreaker if needed) when paginating with LIMIT/OFFSET',
];
