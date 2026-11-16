<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$table         = (string) ($context['table'] ?? 'your_table');
$columns       = $context['columns'] ?? [];
$migrationCode = (string) ($context['migration_code'] ?? '');
$e             = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$columnsList   = is_array($columns) ? implode(', ', $columns) : (string) $columns;
ob_start();
?>
<?php echo suggestionHeader('Add a database index'); ?>
<div class="suggestion-content">
<div class="alert alert-info">
    Queries filter, join or sort <code><?php echo $e($table); ?></code> on <code><?php echo $e($columnsList); ?></code> without an index, so the database reads the whole table.
    An index makes these reads faster at a small cost on each write.
</div>
<div class="query-item"><?php echo formatSqlWithHighlight($migrationCode); ?></div>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Add an index on %s(%s)', $table, $columnsList)];
