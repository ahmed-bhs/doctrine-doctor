<?php

declare(strict_types=1);

/**
 * Template for Too Many JOINs suggestion.
 * Context variables:
 * @var int    $join_count - Number of JOINs detected
 * @var string $sql - SQL query excerpt
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$joinCount = $context['join_count'] ?? null;
$sql = $context['sql'] ?? null;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Split the query into smaller ones'); ?>
<div class="suggestion-content">
<div class="alert alert-danger">
    The query has <?php echo (int) $joinCount; ?> JOINs. Each fetch-joined table adds columns to hydrate, and each to-many JOIN multiplies the rows.
</div>

<div class="query-item"><pre><code class="language-php">// Before: every branch in one query
$qb-&gt;select('o', 'c', 'i', 'p')
   -&gt;leftJoin('o.customer', 'c')
   -&gt;leftJoin('o.items', 'i')-&gt;leftJoin('i.product', 'p');

// After: second branch in its own query, merged by the identity map
$orders = $qb-&gt;select('o', 'c')-&gt;leftJoin('o.customer', 'c')
   -&gt;getQuery()-&gt;getResult();
$em-&gt;createQuery('SELECT o, i, p FROM App\Entity\Order o
    LEFT JOIN o.items i LEFT JOIN i.product p WHERE o IN (:orders)')
   -&gt;setParameter('orders', $orders)
   -&gt;getResult();</code></pre></div>

<p>For read-only screens, select only the displayed columns into a DTO with <code>SELECT NEW</code>.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/query-builder.html', 'Doctrine Query Builder'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Split the query with %d JOINs into smaller queries or use DTOs', $joinCount),
];
