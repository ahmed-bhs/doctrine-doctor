<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$column = $context['column'] ?? '';
$originalQuery = $context['original_query'] ?? '';
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>NOT IN (SELECT ...) NULL pitfall</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code><?= $e((string) $column) ?> NOT IN (SELECT ...)</code> returns zero rows as soon as the subquery contains a single NULL value.
        SQL three-valued logic: <code>x NOT IN (a, b, NULL)</code> evaluates to UNKNOWN, never TRUE.
        Tests pass with clean data, production breaks silently the first time a NULL appears.
    </div>

    <h4>Original query</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= $e((string) $originalQuery) ?></code></pre>
    </div>

    <h4>Fix 1: NOT EXISTS (recommended)</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: silent zero-row bug if the subquery contains NULL
$qb-&gt;select('u')
   -&gt;from(User::class, 'u')
   -&gt;andWhere('u.id NOT IN (SELECT b.userId FROM Banned b)');

// After: NOT EXISTS handles NULL correctly and is usually faster
$qb-&gt;select('u')
   -&gt;from(User::class, 'u')
   -&gt;andWhere('NOT EXISTS (
        SELECT 1 FROM Banned b WHERE b.userId = u.id
   )');</code></pre>
    </div>

    <h4>Fix 2: LEFT JOIN ... IS NULL (anti-join)</h4>
    <div class="query-item">
        <pre><code class="language-sql">-- Functionally equivalent, sometimes preferred by the planner
SELECT u.*
FROM users u
LEFT JOIN banned b ON b.user_id = u.id
WHERE b.user_id IS NULL;</code></pre>
    </div>

    <h4>Fix 3: keep NOT IN but exclude NULLs explicitly</h4>
    <div class="query-item">
        <pre><code class="language-sql">-- Only safe if the subquery has a stable schema you control
WHERE u.id NOT IN (
    SELECT b.user_id FROM banned b WHERE b.user_id IS NOT NULL
);</code></pre>
    </div>

    <p><a href="https://use-the-index-luke.com/sql/where-clause/null/not-in" target="_blank" rel="noopener noreferrer" class="doc-link">Use The Index, Luke! NOT IN and NULLs</a></p>
</div>

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Replace NOT IN (SELECT ...) with NOT EXISTS or LEFT JOIN ... IS NULL to handle NULLs correctly',
];
