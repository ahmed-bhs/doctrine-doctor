<?php

declare(strict_types=1);

/**
 * Template for Missing Database Index suggestions.
 * Context variables:
 * @var string $table_display - Table name with alias (e.g., "time_entry t0_")
 * @var string $real_table_name - Real table name (e.g., "time_entry")
 * @var string $columns_list - Comma-separated column names
 * @var string $index_name - Suggested index name
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$tableDisplay = $context['table_display'] ?? null;
$realTableName = $context['real_table_name'] ?? null;
$columnsList = $context['columns_list'] ?? null;
$indexName = $context['index_name'] ?? null;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Add the missing index'); ?>
<div class="suggestion-content">
<div class="alert alert-danger">
    <?php echo t('No index covers <code>%s</code> (%s), so the query scans the whole table.', $e($tableDisplay), $e($columnsList)); ?>
</div>

<div class="query-item"><pre><code class="language-php">public function up(Schema $schema): void
{
    $this-&gt;addSql('CREATE INDEX <?php echo $e($indexName); ?> ON <?php echo $e($realTableName); ?> (<?php echo $e($columnsList); ?>)');
}</code></pre></div>

<p><?php echo t('Or declare it on the entity: <code>%s</code>', "#[ORM\\Index(name: '" . $e($indexName) . "', columns: [" . implode(', ', array_map(fn ($c): string => "'" . $e(trim($c)) . "'", explode(',', (string) $columnsList))) . '])]'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index', 'Doctrine indexing'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Add an index on %s(%s)', (string) $realTableName, (string) $columnsList),
];
