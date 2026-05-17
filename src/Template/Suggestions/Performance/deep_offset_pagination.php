<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$offset = $context['offset'] ?? 0;
$originalQuery = $context['original_query'] ?? '';
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Deep OFFSET pagination</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        OFFSET <?= (int) $offset ?> forces the database to read and discard <?= (int) $offset ?> rows before returning a single row.
        The deeper the page, the slower the query.
    </div>

    <h4>Original query</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= $e((string) $originalQuery) ?></code></pre>
    </div>

    <h4>Solution: keyset (seek) pagination</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: OFFSET grows costly with page depth
$qb-&gt;select('u')
   -&gt;from(User::class, 'u')
   -&gt;orderBy('u.id', 'ASC')
   -&gt;setFirstResult(<?= (int) $offset ?>)
   -&gt;setMaxResults(20);

// After: keyset pagination using last seen id
$qb-&gt;select('u')
   -&gt;from(User::class, 'u')
   -&gt;where('u.id &gt; :lastId')
   -&gt;setParameter('lastId', $lastSeenId)
   -&gt;orderBy('u.id', 'ASC')
   -&gt;setMaxResults(20);</code></pre>
    </div>

    <h4>When you must keep OFFSET</h4>
    <ul>
        <li>Cap the maximum offset at the application level (e.g. forbid page &gt; N).</li>
        <li>Make sure the ORDER BY column is indexed.</li>
        <li>Use a covering index when possible to avoid extra row lookups.</li>
    </ul>

    <p><a href="https://use-the-index-luke.com/no-offset" target="_blank" rel="noopener noreferrer" class="doc-link">Use The Index, Luke! No Offset</a></p>
</div>

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Replace deep OFFSET pagination with keyset (seek) pagination based on a sortable indexed column',
];
