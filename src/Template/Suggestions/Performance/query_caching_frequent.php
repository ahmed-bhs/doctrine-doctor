<?php

declare(strict_types=1);

/**
 * Template for frequent query caching suggestion.
 * Context variables:
 * @var string $sql        Original SQL query
 * @var int    $count      Number of times query was executed
 * @var float  $total_time Total execution time in milliseconds
 * @var float  $avg_time   Average execution time per query
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$sql = $context['sql'] ?? '';
$count = $context['count'] ?? 0;
$totalTime = $context['total_time'] ?? 0.0;
$avgTime = $context['avg_time'] ?? 0.0;

// Decode HTML entities if SQL is already encoded (from Doctrine Profiler)
$sql = html_entity_decode($sql, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$timeSaved = $totalTime - ($avgTime + ($avgTime / 100) * ($count - 1));
$improvement = ($timeSaved / $totalTime) * 100;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Enable Result Cache for Frequent Query</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Query executed <?php echo $count; ?> times (<?php echo number_format($totalTime, 2); ?>ms total, <?php echo number_format($avgTime, 2); ?>ms avg). Result cache saves ~<?php echo number_format($improvement, 0); ?>%.
    </div>

    <h4>Query:</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?php echo $e($sql); ?></code></pre>
    </div>

    <h4>Solution: Enable result cache</h4>
    <div class="query-item">
        <pre><code class="language-php">$query->useResultCache(true, 3600, 'unique_cache_key');</code></pre>
    </div>

    <p>Cache durations: Static (countries) 24h, Products 1h, Stock 5min. Use descriptive keys: <code>'product_' . $id</code>.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Cache frequently executed query (%d executions, %sms total)',
        $count,
        number_format($totalTime, 2),
    ),
];
