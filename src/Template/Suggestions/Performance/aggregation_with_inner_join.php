<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$aggregation = $context['aggregation'] ?? 'COUNT';
$originalQuery = $context['original_query'] ?? $context['query'] ?? 'SELECT ...';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Aggregate without duplicated join rows'); ?>
<div class="suggestion-content">
<div class="alert alert-danger">
    <code><?php echo $e($aggregation); ?>()</code> runs after an INNER JOIN on a one-to-many relation, so a parent with 3 children is counted 3 times.
    The result describes joined rows, not parents.
</div>

<div class="query-item"><pre><code class="language-php">// Before: counts order items, not orders
$qb-&gt;select('COUNT(o.id)')-&gt;from(Order::class, 'o')-&gt;innerJoin('o.items', 'oi');

// After
$qb-&gt;select('COUNT(DISTINCT o.id)')-&gt;from(Order::class, 'o')-&gt;innerJoin('o.items', 'oi');</code></pre></div>

<p>DISTINCT does not fix SUM or AVG: aggregate in a subquery, or replace a JOIN that only filters with <code>EXISTS</code>.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#aggregate-functions', 'Doctrine ORM Aggregate Functions'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Compute %s() without the rows duplicated by the INNER JOIN', $aggregation),
];
