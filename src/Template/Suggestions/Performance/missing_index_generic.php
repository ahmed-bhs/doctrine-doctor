<?php

declare(strict_types=1);

/**
 * Template for Missing Database Index suggestions (generic version).
 * Used when specific columns cannot be automatically extracted from the query.
 *
 * Context variables:
 * @var string $table_display - Table name with alias (e.g., "time_entry t0_")
 * @var string $real_table_name - Real table name (e.g., "time_entry")
 * @var string $query - The SQL query causing the issue
 * @var int $rows_scanned - Number of rows scanned
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$tableDisplay = $context['table_display'] ?? null;
$realTableName = $context['real_table_name'] ?? null;
$query = $context['query'] ?? null;
$rowsScanned = $context['rows_scanned'] ?? 0;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Missing database index</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        Table <strong><?php echo $e($tableDisplay); ?></strong> is performing a full table scan (<?php echo (int) $rowsScanned; ?> rows scanned).
    </div>

    <div class="query-display">
        <h5>Query:</h5>
        <pre><code class="language-sql"><?php echo $e($query); ?></code></pre>
    </div>

    <h4>Recommended Action</h4>
    <ol>
        <li><strong>Analyze the query</strong> to identify which columns are used in WHERE, JOIN, or ORDER BY clauses</li>
        <li><strong>Run EXPLAIN</strong> on your database to confirm the missing index</li>
        <li><strong>Add an index</strong> via migration or entity annotation</li>
    </ol>

    <h5>Example: Add index via migration</h5>
    <div class="query-item">
        <pre><code class="language-php">public function up(Schema $schema): void
{
    // Replace [column1, column2] with actual columns from your WHERE/JOIN clauses
    $this->addSql('CREATE INDEX IDX_<?php echo strtoupper($e($realTableName)); ?>_COLUMNS ON <?php echo $e($realTableName); ?> (column1, column2)');
}</code></pre>
    </div>

    <h5>Example: Add index via entity annotation</h5>
    <div class="code-example">
        <pre><code class="language-php">#[ORM\Index(name: 'IDX_<?php echo strtoupper($e($realTableName)); ?>_COLUMNS', columns: ['column1', 'column2'])]</code></pre>
    </div>

    <div class="info-box">
        <p><strong>💡 Tip:</strong> Focus on columns used in:</p>
        <ul>
            <li>WHERE clauses (equality and range conditions)</li>
            <li>JOIN ON conditions</li>
            <li>ORDER BY clauses</li>
        </ul>
        <p>For composite indexes, put the most selective column first.</p>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index" target="_blank" class="doc-link">
            📖 Doctrine indexing documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Table %s needs an index to avoid full table scan (%d rows scanned)',
        $realTableName,
        $rowsScanned,
    ),
];
