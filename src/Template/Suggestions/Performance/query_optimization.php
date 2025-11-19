<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $code
 * @var mixed $optimization
 * @var mixed $executionTime
 * @var mixed $threshold
 * @var mixed $context
 */
['code' => $code, 'optimization' => $optimization, 'execution_time' => $executionTime, 'threshold' => $threshold] = $context;
ob_start();
?>
<div class="suggestion-header"><h4>Slow Query Optimization</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><strong>Slow Query Detected</strong><br>
Execution time: <strong><?php echo number_format($executionTime, 2); ?>ms</strong> (threshold: <?php echo $threshold; ?>ms)</div>
<h4>Query</h4>
<div class="query-item"><?php
// Detect if code is PHP or SQL
$isPHP = str_contains($code, '$') || str_contains($code, '->') || str_contains($code, '::');

if ($isPHP) {
    // Display PHP code without SQL formatting
    echo '<pre><code class="language-php">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code></pre>';
} else {
    // Display SQL with syntax highlighting
    echo '<pre><code class="language-sql">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code></pre>';
}
?></div>
<h4>Suggested Optimization</h4>
<p><?php echo nl2br(htmlspecialchars($optimization, ENT_QUOTES, 'UTF-8')); ?></p>
<h4>Common Optimizations</h4>
<ul>
<li>Add indexes on WHERE/JOIN columns</li>
<li>Use JOIN FETCH for eager loading</li>
<li>Limit result set with LIMIT/pagination</li>
<li>Avoid SELECT * (use partial objects)</li>
<li>Use Query Result Cache for repeated queries</li>
</ul>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Optimize slow query (%.2fms)', $executionTime)];
