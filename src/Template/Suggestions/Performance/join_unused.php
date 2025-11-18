<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $type
 * @var mixed $table
 * @var mixed $alias
 * @var mixed $context
 */
['type' => $type, 'table' => $table, 'alias' => $alias] = $context;
$e                                                      = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Unused JOIN Detected</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Unnecessary Query Operation</strong><br>
        <?php echo $e($type); ?> JOIN on table '<?php echo $e($table); ?>' (alias '<?php echo $e($alias); ?>') but it's never used.
    </div>

    <h4>Problem</h4>
    <ul>
        <li>Database performs the JOIN operation</li>
        <li>But alias '<?php echo $e($alias); ?>' is never referenced</li>
        <li>Wasted database resources</li>
    </ul>

    <h4>Solution 1: Remove the JOIN</h4>
    <div class="query-item">
        <pre><code class="language-php">// Remove this line:
->leftJoin('o.relation', '<?php echo $e($alias); ?>')  // ← Not used anywhere</code></pre>
    </div>

    <h4>Solution 2: Use the JOIN (Fetch the Data)</h4>
    <div class="query-item">
        <pre><code class="language-php">// If you want to preload the relation:
->leftJoin('o.relation', '<?php echo $e($alias); ?>')
->addSelect('<?php echo $e($alias); ?>')  // ← Add this</code></pre>
    </div>

    <h4>Solution 3: Use for Filtering</h4>
    <div class="query-item">
        <pre><code class="language-php">// If you want to filter by the relation:
->innerJoin('o.relation', '<?php echo $e($alias); ?>')
->where('<?php echo $e($alias); ?>.status = :status')</code></pre>
    </div>

    <div class="alert alert-info">
        <strong>Performance Impact:</strong><br>
        Removing unused JOIN: 10-20% faster
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove unused JOIN or add it to SELECT clause',
];
