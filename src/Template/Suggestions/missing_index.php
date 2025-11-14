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
    <h4>Missing database index</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>No index found on <?php echo $e($tableDisplay); ?></strong><br>
        Columns: <code><?php echo $e($columnsList); ?></code>
    </div>

    <p>Without an index on these columns, the database has to scan the entire table for every query. This works fine with a few hundred rows, but as your table grows, queries will get noticeably slower.</p>

    <h4>How to add an index</h4>

    <h5>Quick test with SQL</h5>
    <div class="query-item">
        <?php echo formatSqlWithHighlight("CREATE INDEX {$indexName} ON {$realTableName} ({$columnsList});"); ?>
    </div>

    <h5>In a migration (recommended)</h5>
    <div class="query-item">
        <pre><code class="language-php">public function up(Schema $schema): void
{
    $this->addSql('CREATE INDEX <?php echo $e($indexName); ?> ON <?php echo $e($realTableName); ?> (<?php echo $e($columnsList); ?>)');
}</code></pre>
    </div>

    <h5>In your entity</h5>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Table(name: '<?php echo $e($realTableName); ?>')]
#[ORM\Index(name: '<?php echo $e($indexName); ?>', columns: [<?php echo implode(', ', array_map(fn ($c): string => "'" . $e(trim($c)) . "'", explode(',', (string) $columnsList))); ?>])]
class YourEntity
{
    // ...
}</code></pre>
    </div>

    <p>Indexes speed up reads but add a small overhead to writes. Focus on indexing columns you actually query on — typically those in WHERE, JOIN, and ORDER BY clauses. You can use EXPLAIN to check if your indexes are being used.</p>

    <p>For tables with more than 10k rows, the difference is usually quite noticeable. Query time goes from scanning every row to a quick lookup.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index" target="_blank" class="doc-link">
            Doctrine indexing docs
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
