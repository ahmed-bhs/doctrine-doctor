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
    No index covers <code><?php echo $e($tableDisplay); ?></code> (<?php echo $e($columnsList); ?>), so the query scans the whole table.
</div>

<div class="query-item"><pre><code class="language-php">public function up(Schema $schema): void
{
    $this-&gt;addSql('CREATE INDEX <?php echo $e($indexName); ?> ON <?php echo $e($realTableName); ?> (<?php echo $e($columnsList); ?>)');
}</code></pre></div>

<p>Or declare it on the entity: <code>#[ORM\Index(name: '<?php echo $e($indexName); ?>', columns: [<?php echo implode(', ', array_map(fn ($c): string => "'" . $e(trim($c)) . "'", explode(',', (string) $columnsList))); ?>])]</code></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index', 'Doctrine indexing'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Add an index on %s(%s)', $realTableName, $columnsList),
];
