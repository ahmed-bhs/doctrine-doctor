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
<?php echo suggestionHeader('Compare the date column to a range'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <code><?php echo $e($function); ?>(<?php echo $e((string) $field); ?>)</code> is computed for every row, so the index on <code><?php echo $e((string) $field); ?></code> cannot be used.
    A range on the bare column can use it.
</div>

<div class="query-item"><pre><code class="language-php">// Before: WHERE <?php echo $e((string) $originalClause); ?>

// After:  WHERE <?php echo $e((string) $optimizedClause); ?>

$qb-&gt;andWhere('o.createdAt &gt;= :start AND o.createdAt &lt; :end')
   -&gt;setParameter('start', new \DateTimeImmutable('2023-01-01'))
   -&gt;setParameter('end', new \DateTimeImmutable('2024-01-01'));</code></pre></div>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Replace %s() with a range comparison so the index can be used', $function),
];
