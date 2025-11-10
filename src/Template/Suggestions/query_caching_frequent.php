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
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Enable Result Cache for Frequent Query</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Query Executed <?php echo $count; ?> Times</strong><br>
        This query was executed <strong><?php echo $count; ?> times</strong> (<?php echo number_format($totalTime, 2); ?>ms total, <?php echo number_format($avgTime, 2); ?>ms avg).
        Using result cache would save ~<?php echo number_format($timeSaved, 2); ?>ms (<?php echo number_format($improvement, 0); ?>% faster) by avoiding <?php echo $count - 1; ?> redundant database hits.
    </div>

    <h4>Query Executed:</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?php echo $e($sql); ?></code></pre>
    </div>

    <h4>Solution: Enable Result Cache</h4>
    <div class="query-item">
        <pre><code class="language-php">$query->useResultCache(true, 3600, 'unique_cache_key');</code></pre>
    </div>

    <h4>Performance Impact</h4>
    <ul>
        <li><strong>Current:</strong> <?php echo $count; ?> DB hits → <?php echo number_format($totalTime, 2); ?>ms wasted</li>
        <li><strong>With cache:</strong> 1 DB hit + <?php echo $count - 1; ?> cache hits</li>
        <li><strong>Speed improvement:</strong> ~<?php echo number_format($improvement, 0); ?>% faster</li>
        <li><strong>Fewer DB hits:</strong> <?php echo $count - 1; ?> redundant queries eliminated</li>
    </ul>

    <h4>Cache Duration Guide</h4>
    <ul>
        <li><strong>Static data</strong> (countries, currencies): 86400s (24h)</li>
        <li><strong>Products/catalog</strong>: 3600s (1h)</li>
        <li><strong>Stock/inventory</strong>: 300s (5min)</li>
        <li><strong>User sessions</strong>: 300s (5min)</li>
    </ul>

    <h4>Cache Key Best Practices</h4>
    <div class="alert alert-info">
        <strong>Good cache keys:</strong><br>
        <ul>
            <li><code>'product_' . $id</code> - Unique per product</li>
            <li><code>'users_active_count'</code> - Descriptive name</li>
            <li><code>'order_items_' . $orderId</code> - Unique per order</li>
        </ul>

        <strong>Bad cache keys:</strong><br>
        <ul>
            <li><code>'products'</code> - Too generic, causes collisions</li>
            <li><code>'data'</code> - Meaningless, hard to debug</li>
            <li>User-specific data with shared key - Privacy/security risk</li>
        </ul>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Cache frequently executed query (%d executions, %sms total)',
        $count,
        number_format($total_time, 2),
    ),
];
