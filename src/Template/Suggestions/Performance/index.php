<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $table
 * @var mixed $columns
 * @var mixed $migrationCode
 * @var mixed $context
 */
['table' => $table, 'columns' => $columns, 'migration_code' => $migrationCode] = $context;
$e                                                                             = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$columnsList                                                                   = is_array($columns) ? implode(', ', $columns) : $columns;
ob_start();
?>
<div class="suggestion-header"><h4>Add Database Index</h4></div>
<div class="suggestion-content">
<div class="alert alert-info"> <strong>Missing Index Suggestion</strong><br>
Table: <code><?php echo $e($table); ?></code><br>
Columns: <code><?php echo $e($columnsList); ?></code></div>
<h4>Migration Code</h4>
<div class="query-item"><?php echo formatSqlWithHighlight($migrationCode); ?></div>
<h4>Why Add an Index?</h4>
<ul>
<li>Speeds up queries using WHERE, JOIN, or ORDER BY on these columns</li>
<li>Reduces full table scans</li>
<li>Improves query performance from O(n) to O(log n)</li>
</ul>
<p><strong>Trade-off:</strong> Indexes speed up reads but slightly slow down writes (INSERT/UPDATE/DELETE).</p>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Add index on %s(%s)', $table, $columnsList)];
