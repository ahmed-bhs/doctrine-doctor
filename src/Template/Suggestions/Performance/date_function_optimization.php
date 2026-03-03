<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$function = $context['function'] ?? $context['function_name'] ?? 'YEAR';
$field = $context['field'] ?? $context['field_name'] ?? 'created_at';
$originalClause = $context['original_clause'] ?? $context['query'] ?? "{$function}({$field}) = value";
$optimizedClause = $context['optimized_clause'] ?? "{$field} BETWEEN start AND end";

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>Date function prevents index usage</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning">Using <code><?php echo $e($function); ?>()</code> on <code><?php echo $e((string) $field); ?></code> prevents index usage, forcing a full table scan.</div>

<h4>Your query</h4>
<div class="query-item"><pre><code class="language-sql">WHERE <?php echo $e((string) $originalClause); ?></code></pre></div>

<h4>Solution: Use range comparison</h4>
<div class="query-item"><pre><code class="language-sql">WHERE <?php echo $e((string) $optimizedClause); ?></code></pre></div>

<h4>Examples</h4>
<div class="query-item"><pre><code class="language-sql">-- Slow (full scan)
WHERE YEAR(created_at) = 2023
WHERE DATE(created_at) = '2023-01-15'

-- Fast (uses index)
WHERE created_at >= '2023-01-01' AND created_at < '2024-01-01'
WHERE created_at >= '2023-01-15' AND created_at < '2023-01-16'</code></pre></div>

<h4>Doctrine QueryBuilder</h4>
<div class="query-item"><pre><code class="language-php">// Slow
$qb->where('YEAR(o.createdAt) = :year')
   ->setParameter('year', 2023);

// Fast
$qb->where('o.createdAt BETWEEN :start AND :end')
   ->setParameter('start', new \DateTime('2023-01-01'))
   ->setParameter('end', new \DateTime('2023-12-31'));</code></pre></div>

<p><strong>Never use functions on indexed columns in WHERE clause.</strong> Common culprits: <code>YEAR()</code>, <code>MONTH()</code>, <code>DATE()</code>, <code>UPPER()</code>, <code>LOWER()</code>.</p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Replace %s() with range comparison for index usage', $function),
];
