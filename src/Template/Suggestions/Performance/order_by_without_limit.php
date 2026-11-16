<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$orderByClause = (string) ($context['order_by_clause'] ?? 'ORDER BY id');
$originalQuery = (string) ($context['original_query'] ?? '');
$queryContext  = (string) ($context['query_context'] ?? '');
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

$isBoundedArrayResult = 'array_result' === $queryContext
    && 1 === preg_match('/\bWHERE\b/i', $originalQuery);

ob_start();
?>
<?php echo suggestionHeader($isBoundedArrayResult ? 'Index the ORDER BY column or add a LIMIT' : 'Add a LIMIT or remove the ORDER BY'); ?>
<div class="suggestion-content">
<?php if ($isBoundedArrayResult) { ?>
<div class="alert alert-info">
    The WHERE clause bounds the result today, but <code><?= $e($orderByClause) ?></code> without LIMIT sorts every matching row.
    The sort grows with the related data.
</div>

<div class="query-item"><pre><code class="language-php">// Only the latest entries are needed: add a LIMIT
$qb-&gt;orderBy('h.occurredAt', 'DESC')-&gt;setMaxResults(100);

// All entries are needed: index the sort column after the filter column
#[ORM\Index(columns: ['entity_id', 'occurred_at'])]</code></pre></div>
<?php } else { ?>
<div class="alert alert-warning">
    <code><?= $e($orderByClause) ?></code> without LIMIT sorts every row the query returns.
    Either only the first rows are used, or the sort is not needed.
</div>

<div class="query-item"><pre><code class="language-php">// Before
$qb-&gt;select('u')-&gt;from(User::class, 'u')-&gt;orderBy('u.createdAt', 'DESC');

// After: only the first rows are used
$qb-&gt;select('u')-&gt;from(User::class, 'u')-&gt;orderBy('u.createdAt', 'DESC')
   -&gt;setMaxResults(10);</code></pre></div>

<p>If the order does not matter, remove the ORDER BY.</p>
<?php } ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#first-and-max-result-items-dql-query-only', 'Doctrine ORM First and Max Result Items'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => $isBoundedArrayResult
        ? 'Index the ORDER BY column or add a LIMIT before the related data grows'
        : 'Add a LIMIT when using ORDER BY, or remove the ORDER BY',
];
