<?php

declare(strict_types=1);

/**
 * Template for Flush in Loop suggestions.
 * Context variables:
 * @var int   $flush_count - Number of flush() calls detected
 * @var float $operations_between_flush - Average operations between flushes
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$flushCount = $context['flush_count'] ?? null;
$operationsBetweenFlush = $context['operations_between_flush'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Performance Anti-Pattern: flush() in Loop</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Performance Anti-Pattern Detected</strong><br>
        Detected <strong><?php echo $flushCount; ?> flush() calls</strong> in a loop.
        This causes severe performance degradation!
    </div>

    <h4>The Problem: Flushing Every Iteration</h4>
    <div class="query-item">
        <pre><code class="language-php">// 📢 BAD: <?php echo $flushCount; ?> flush() calls
assert(is_iterable($items), '$items must be iterable');

foreach ($items as $item) {
    $entity = new Entity();
    $entity->setData($item);
    $em->persist($entity);
    $em->flush();  // Flushing after EVERY item!
}
// Result: <?php echo $flushCount; ?> separate database transactions</code></pre>
    </div>

    <h4> Solution: Batch Processing</h4>
    <div class="query-item">
        <pre><code class="language-php">//  GOOD: Batch flush every 20 items
$batchSize = 20;
$i = 0;

assert(is_iterable($items), '$items must be iterable');


foreach ($items as $item) {
    $entity = new Entity();
    $entity->setData($item);
    $em->persist($entity);

    if (($i % $batchSize) === 0) {
        $em->flush();
        $em->clear(); // Detaches all objects from Doctrine to save memory
    }
    $i++;
}

// Flush remaining entities
$em->flush();
$em->clear();

// Result: Only <?php echo ceil($flushCount / 20); ?> flush() calls instead of <?php echo $flushCount; ?></code></pre>
    </div>

    <h4>Performance Impact</h4>
    <ul>
        <li><strong>Current:</strong> <?php echo $flushCount; ?> database transactions</li>
        <li><strong>With batch processing:</strong> ~<?php echo ceil($flushCount / 20); ?> transactions</li>
        <li><strong>Speed improvement:</strong> Up to <?php echo round((1 - (ceil($flushCount / 20) / $flushCount)) * 100); ?>% faster</li>
        <li><strong>Memory usage:</strong> Significantly reduced with clear()</li>
    </ul>

    <h4>Best Practices</h4>
    <ul>
        <li>Batch size: 20-50 for inserts, 10-20 for updates</li>
        <li>Always call clear() after flush() to free memory</li>
        <li>Flush remaining entities after the loop</li>
        <li>Use transactions for better rollback control</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Why This Matters:</strong><br>
        Each flush() triggers a full database transaction with COMMIT.
        This includes overhead for:
        <ul>
            <li>Transaction management</li>
            <li>Query generation and execution</li>
            <li>Index updates</li>
            <li>Database locks</li>
        </ul>
        Batching reduces this overhead by <?php echo round((1 - (ceil($flushCount / 20) / $flushCount)) * 100); ?>%!
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/batch-processing.html" target="_blank" class="doc-link">
            📖 Doctrine Batch Processing Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Use batch processing instead of flush() in loop (%d calls detected)',
        $flushCount,
    ),
];
