<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$originalQuery = $context['original_query'] ?? '';
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Add an ORDER BY to paginated queries'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    LIMIT or OFFSET without ORDER BY returns rows in an undefined order, so two pages can repeat or skip rows.
    Sort on a unique column, or add the primary key as a tie-breaker.
</div>

<div class="query-item"><pre><code class="language-php">// Before
$qb-&gt;select('u')-&gt;from(User::class, 'u')
   -&gt;setFirstResult(20)-&gt;setMaxResults(20);

// After
$qb-&gt;select('u')-&gt;from(User::class, 'u')
   -&gt;orderBy('u.createdAt', 'DESC')
   -&gt;addOrderBy('u.id', 'DESC')
   -&gt;setFirstResult(20)-&gt;setMaxResults(20);</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/pagination.html', 'Doctrine ORM Pagination'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Add a deterministic ORDER BY when paginating with LIMIT/OFFSET',
];
