<?php

declare(strict_types=1);

/**
 * Template for Missing Database Index suggestions (generic version).
 * Used when specific columns cannot be automatically extracted from the query.
 * Context variables:
 * @var string $table_display - Table name with alias (e.g., "time_entry t0_")
 * @var string $real_table_name - Real table name (e.g., "time_entry")
 * @var string $query - The SQL query causing the issue
 * @var int $rows_scanned - Number of rows scanned
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$tableDisplay = $context['table_display'] ?? null;
$realTableName = $context['real_table_name'] ?? null;
$query = $context['query'] ?? null;
$rowsScanned = $context['rows_scanned'] ?? 0;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$indexName = 'IDX_' . strtoupper((string) $realTableName) . '_COLUMNS';

ob_start();
?>
<?php echo suggestionHeader('Add an index for this query'); ?>
<div class="suggestion-content">
<div class="alert alert-danger">
    <?php echo t('<code>%s</code> was scanned in full (%d rows).', $e($tableDisplay), (int) $rowsScanned); ?>
    <?php echo t('Run EXPLAIN on the query, then index the columns it uses in WHERE, JOIN and ORDER BY.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">// Replace column1, column2 with the columns of the query
public function up(Schema $schema): void
{
    $this-&gt;addSql('CREATE INDEX <?php echo $e($indexName); ?> ON <?php echo $e($realTableName); ?> (column1, column2)');
}

// Or on the entity
#[ORM\Index(name: '<?php echo $e($indexName); ?>', columns: ['column1', 'column2'])]</code></pre></div>

<p><?php echo t('In a composite index, put the columns compared with <code>=</code> before the ones used in a range.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index', 'Doctrine indexing documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Add an index on %s to avoid a full scan (%d rows scanned)', (string) $realTableName, (int) $rowsScanned),
];
