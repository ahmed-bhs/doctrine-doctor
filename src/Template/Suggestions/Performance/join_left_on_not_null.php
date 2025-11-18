<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $table
 * @var mixed $alias
 * @var mixed $entity
 * @var mixed $context
 */
['table' => $table, 'alias' => $alias, 'entity' => $entity] = $context;
$e                                                          = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Suboptimal LEFT JOIN on NOT NULL Relation</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Performance:</strong> LEFT JOIN on '<?php echo $e($table); ?>' which has NOT NULL FK. INNER JOIN would be 20-30% faster.
    </div>

    <p>LEFT JOIN includes NULL rows, but your FK is NOT NULL (mandatory). Database never returns NULL here, so LEFT JOIN does extra work.</p>

    <h4>Solution: Use INNER JOIN</h4>
    <pre><code class="language-php">// Current (slow):
$qb->leftJoin('o.relation', '<?php echo $e($alias); ?>');

// Better (20-30% faster):
$qb->innerJoin('o.relation', '<?php echo $e($alias); ?>');</code></pre>

    <p><strong>Rule:</strong> NOT NULL FK → INNER JOIN. Nullable FK → LEFT JOIN.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use INNER JOIN instead of LEFT JOIN on NOT NULL relation',
];
