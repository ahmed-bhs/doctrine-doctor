<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$column = $context['column'] ?? '';
$originalQuery = $context['original_query'] ?? '';
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Replace NOT IN (SELECT ...) with NOT EXISTS'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <code><?= $e((string) $column) ?> NOT IN (SELECT ...)</code> returns no rows as soon as the subquery returns one NULL, because <code>x NOT IN (a, NULL)</code> is never true.
    NOT EXISTS does not have this trap.
</div>

<div class="query-item"><pre><code class="language-php">// Before
$qb-&gt;andWhere('u.id NOT IN (SELECT IDENTITY(b.user) FROM App\Entity\Ban b)');

// After
$qb-&gt;andWhere('NOT EXISTS (SELECT b.id FROM App\Entity\Ban b WHERE b.user = u)');</code></pre></div>

<p>To keep NOT IN, exclude NULLs in the subquery with <code>WHERE b.user IS NOT NULL</code>.</p>

<?php echo suggestionDocLink('https://use-the-index-luke.com/sql/where-clause/null/not-in', 'Use The Index, Luke! NOT IN and NULLs'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Replace NOT IN (SELECT ...) with NOT EXISTS so NULLs do not empty the result',
];
