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
$sql = (string) ($context['sql'] ?? '');
$count = max(0, (int) ($context['count'] ?? 0));
$totalTime = max(0.0, (float) ($context['total_time'] ?? 0.0));
$avgTime = max(0.0, (float) ($context['avg_time'] ?? 0.0));

// Decode HTML entities if SQL is already encoded (from Doctrine Profiler)
$sql = html_entity_decode($sql, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Cache the result of this repeated query'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>">
    The same query ran <?php echo $count; ?> <?php echo 1 === $count ? 'time' : 'times'; ?> (<?php echo number_format($totalTime, 2); ?>ms total, <?php echo number_format($avgTime, 2); ?>ms on average).
    A result cache answers the repeats without reaching the database.
</div>

<div class="query-item"><pre><code class="language-sql"><?php echo $e($sql); ?></code></pre></div>

<div class="query-item"><pre><code class="language-php">$query-&gt;enableResultCache(3600, 'product_' . $id);

// When the data changes, drop the entry
$em-&gt;getConfiguration()-&gt;getResultCache()?-&gt;deleteItem('product_' . $id);</code></pre></div>

<p>If the repeats come from one request, store the result in a variable instead of caching it.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/caching.html', 'Doctrine ORM Caching'); ?>
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
