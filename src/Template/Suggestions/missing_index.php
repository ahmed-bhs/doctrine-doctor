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

// Helper function for SQL formatting with syntax highlighting (like profiler)
$formatSql = function (string $sql): string {
    static $formatter = null;

    if (null === $formatter) {
        $formatter = new Doctrine\SqlFormatter\SqlFormatter(
            new Doctrine\SqlFormatter\HtmlHighlighter(),
        );
    }

    return $formatter->format($sql);
};

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Add Database Index</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Missing Database Index Detected</strong><br>
        Table: <code><?php echo $e($tableDisplay); ?></code><br>
        Columns: <code><?php echo $e($columnsList); ?></code>
    </div>

    <div class="alert alert-warning">
        <strong>Current state:</strong> No index on these columns<br><br>
        <strong>Impact:</strong>
        <ul>
            <li>Full table scan on queries using these columns</li>
            <li>Queries get slower as table grows</li>
            <li>Increased server load and response time</li>
        </ul>
    </div>

    <h4>Solution: Add an Index</h4>

    <h5>Option 1: Direct SQL (for testing)</h5>
    <div class="query-item">
        <?php echo formatSqlWithHighlight("CREATE INDEX {$indexName} ON {$realTableName} ({$columnsList});"); ?>
    </div>

    <h5>Option 2: Doctrine Migration (recommended)</h5>
    <div class="query-item">
        <pre><code class="language-php">public function up(Schema $schema): void
{
    $this->addSql('CREATE INDEX <?php echo $e($indexName); ?> ON <?php echo $e($realTableName); ?> (<?php echo $e($columnsList); ?>)');
}</code></pre>
    </div>

    <h5>Option 3: Entity Annotation</h5>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Table(name: '<?php echo $e($realTableName); ?>')]
#[ORM\Index(name: '<?php echo $e($indexName); ?>', columns: [<?php echo implode(', ', array_map(fn ($c): string => "'" . $e(trim($c)) . "'", explode(',', (string) $columnsList))); ?>])]
class YourEntity
{
    // ...
}</code></pre>
    </div>

    <h4>Index Best Practices</h4>
    <ul>
        <li>Index columns used in WHERE, JOIN, ORDER BY clauses</li>
        <li>Consider composite indexes for multi-column queries</li>
        <li>Don't over-index (indexes slow down INSERT/UPDATE)</li>
        <li>Monitor index usage with EXPLAIN queries</li>
        <li>Drop unused indexes to save storage and write performance</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li>Query time: Reduced from O(n) to O(log n)</li>
            <li>Especially beneficial for large tables (&gt;10k rows)</li>
            <li>Significant speedup for frequently executed queries</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index" target="_blank" class="doc-link">
            📖 Doctrine Index Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Add index on %s(%s) to improve query performance',
        $realTableName,
        $columnsList,
    ),
];
