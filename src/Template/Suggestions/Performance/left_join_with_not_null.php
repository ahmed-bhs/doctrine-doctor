<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $field
 * @var mixed $entity
 * @var mixed $context
 */
['field' => $field, 'entity' => $entity] = $context;
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>LEFT JOIN on NOT NULL Field</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Using LEFT JOIN on <code><?= $e($field) ?></code> which is NOT NULL - use INNER JOIN instead for better performance.
    </div>

    <h4>Solution: Use INNER JOIN</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: Inefficient LEFT JOIN on required field
$qb->select('e')
   ->from(<?= $e($entity) ?>::class, 'e')
   ->leftJoin('e.<?= $e($field) ?>', 'f');  // Field is NOT NULL!

// After: Efficient INNER JOIN
$qb->select('e')
   ->from(<?= $e($entity) ?>::class, 'e')
   ->innerJoin('e.<?= $e($field) ?>', 'f');  // No NULL checks needed</code></pre>
    </div>

    <p><strong>Why:</strong> LEFT JOIN includes NULL checks which are unnecessary for NOT NULL fields. INNER JOIN is faster.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use INNER JOIN instead of LEFT JOIN on NOT NULL field',
];
