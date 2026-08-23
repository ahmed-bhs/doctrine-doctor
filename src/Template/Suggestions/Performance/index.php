<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $table
 * @var mixed $columns
 * @var string $migrationCode
 * @var array<string, mixed> $context
 */
$table         = (string) ($context['table'] ?? 'your_table');
$columns       = $context['columns'] ?? [];
$migrationCode = (string) ($context['migration_code'] ?? '');
$e             = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$columnsList   = is_array($columns) ? implode(', ', $columns) : (string) $columns;
ob_start();
?>
<?php echo suggestionHeader('Add Database Index'); ?>
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

return ['code' => $code, 'description' => sprintf('Add an index on %s(%s)', $table, $columnsList)];
