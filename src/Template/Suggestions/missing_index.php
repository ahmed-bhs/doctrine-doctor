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
// Extract context for clarity
$tableDisplay = $context['table_display'] ?? null;
$realTableName = $context['real_table_name'] ?? null;
$columnsList = $context['columns_list'] ?? null;
$indexName = $context['index_name'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Missing database index</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        No index on <strong><?php echo $e($tableDisplay); ?></strong> (<?php echo $e($columnsList); ?>) causes full table scan.
    </div>

    <h4>Solution: Add index via migration</h4>
    <div class="query-item">
        <pre><code class="language-php">public function up(Schema $schema): void
{
    $this->addSql('CREATE INDEX <?php echo $e($indexName); ?> ON <?php echo $e($realTableName); ?> (<?php echo $e($columnsList); ?>)');
}</code></pre>
    </div>

    <p>Or via entity annotation: <code>#[ORM\Index(name: '<?php echo $e($indexName); ?>', columns: [<?php echo implode(', ', array_map(fn ($c): string => "'" . $e(trim($c)) . "'", explode(',', (string) $columnsList))); ?>])]</code></p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index" target="_blank" class="doc-link">
            📖 Doctrine indexing →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Consider adding an index on %s(%s)',
        $realTableName,
        $columnsList,
    ),
];
