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

<?php if ('COUNT' === strtoupper((string) $aggregation)) { ?>
<div class="query-item"><pre><code class="language-php">// Before: counts order items, not orders
$qb-&gt;select('COUNT(o.id)')-&gt;from(Order::class, 'o')-&gt;innerJoin('o.items', 'oi');

// After
$qb-&gt;select('COUNT(DISTINCT o.id)')-&gt;from(Order::class, 'o')-&gt;innerJoin('o.items', 'oi');</code></pre></div>

<p>DISTINCT fixes COUNT only. For SUM or AVG, two parents with the same value would be merged.</p>
<?php } else { ?>
<div class="query-item"><pre><code class="language-php">// Before: each order is summed once per item
$qb-&gt;select('<?php echo $e(strtoupper((string) $aggregation)); ?>(o.total)')
    -&gt;from(Order::class, 'o')
    -&gt;innerJoin('o.items', 'oi')
    -&gt;where('oi.quantity &gt; 0');

// After: the join only filtered, so it becomes EXISTS
$qb-&gt;select('<?php echo $e(strtoupper((string) $aggregation)); ?>(o.total)')
    -&gt;from(Order::class, 'o')
    -&gt;where('EXISTS (SELECT oi.id FROM App\Entity\OrderItem oi WHERE oi.order = o AND oi.quantity &gt; 0)');</code></pre></div>

<p>Do not add DISTINCT: <code><?php echo $e(strtoupper((string) $aggregation)); ?>(DISTINCT o.total)</code> merges two orders of the same amount. If the query needs values from the children too, aggregate them in a separate query.</p>
<?php } ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#aggregate-functions', 'Doctrine ORM Aggregate Functions'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Compute %s() without the rows duplicated by the INNER JOIN', $aggregation),
];
