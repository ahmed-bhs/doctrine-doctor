<?php

declare(strict_types=1);

/**
 * Template for static table caching suggestion.
 * @var string $sql        Original SQL query
 * @var string $table_name Static table name
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$sql = $context['sql'] ?? 'SELECT * FROM static_table';
$table_name = $context['table_name'] ?? 'static_table';

// Decode HTML entities if SQL is already encoded (from Doctrine Profiler)
$sql = html_entity_decode($sql, ENT_QUOTES | ENT_HTML5, 'UTF-8');

ob_start();
?>

<div class="static-table-caching">
    <div class="header">
        <p> Query on static table <code><?= htmlspecialchars($table_name) ?></code> (rarely-changing lookup data)</p>
    </div>

    <div class="original-query">
        <p><strong>Query:</strong></p>
        <pre><code class="language-sql"><?= htmlspecialchars($sql) ?></code></pre>
    </div>

    <div class="solution">
        <h3>Solution: Cache for 24h</h3>
        <pre><code class="language-php">// In repository
$query->useResultCache(true, 86400, '<?= htmlspecialchars($table_name) ?>_all');</code></pre>
    </div>

    <div class="impact">
        <p><strong>Impact:</strong> 50-200x faster, 99% less DB load</p>
    </div>

    <div class="cache-durations">
        <h3>Cache durations:</h3>
        <ul>
            <li>Never changes (countries): 604800s (1 week)</li>
            <li>Rarely changes (categories): 86400s (24h)</li>
            <li>Occasionally (settings): 3600s (1h)</li>
        </ul>
    </div>

    <div class="cache-invalidation">
        <h3>Invalidation (when data changes):</h3>
        <pre><code class="language-php">$cacheDriver->delete('<?= htmlspecialchars($table_name) ?>_all');</code></pre>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
