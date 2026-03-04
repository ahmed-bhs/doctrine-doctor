<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$aggregation = $context['aggregation'] ?? 'COUNT';
$originalQuery = $context['original_query'] ?? $context['query'] ?? 'SELECT ...';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4><?php echo $e($aggregation); ?>() with INNER JOIN - wrong results</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><strong>Critical:</strong> Using <code><?php echo $e($aggregation); ?>()</code> with <code>INNER JOIN</code> on one-to-many relationships causes <strong>row duplication</strong> and incorrect aggregates.</div>

<h4>Problem</h4>
<div class="query-item"><pre><code class="language-sql">-- WRONG: Returns ITEMS count, not ORDERS count!
SELECT COUNT(o.id)
FROM orders o
INNER JOIN order_items oi ON oi.order_id = o.id;
-- If Order #1 has 3 items, it gets counted 3 times!</code></pre></div>

<h4>Solution: Use COUNT(DISTINCT)</h4>
<div class="query-item"><pre><code class="language-sql">SELECT COUNT(DISTINCT o.id)
FROM orders o
INNER JOIN order_items oi ON oi.order_id = o.id;</code></pre></div>

<h4>Doctrine QueryBuilder</h4>
<div class="query-item"><pre><code class="language-php">$qb->select('COUNT(DISTINCT o.id)')
   ->from(Order::class, 'o')
   ->innerJoin('o.items', 'oi');</code></pre></div>

<p>Use <code>COUNT(DISTINCT)</code>, remove unnecessary JOINs, or use subqueries.</p>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#aggregate-functions" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Aggregate Functions</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Use COUNT(DISTINCT) with %s() and INNER JOIN to avoid row duplication', $aggregation),
];
