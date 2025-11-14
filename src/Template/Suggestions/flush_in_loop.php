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
    <h4>Calling flush() inside a loop</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Found <?php echo $flushCount; ?> flush() calls</strong> inside a loop. This will slow down your application considerably.
    </div>

    <p>When you call flush() in every iteration, you're creating a separate database transaction each time. That's <?php echo $flushCount; ?> transactions when you only need one.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">foreach ($items as $item) {
    $entity = new Entity();
    $entity->setData($item);
    $em->persist($entity);
    $em->flush();  // Creates a transaction every time
}
// Total: <?php echo $flushCount; ?> separate transactions</code></pre>
    </div>

    <h4>Better approach</h4>
    <div class="query-item">
        <pre><code class="language-php">// Process in batches
$batchSize = 20;
$i = 0;

foreach ($items as $item) {
    $entity = new Entity();
    $entity->setData($item);
    $em->persist($entity);

    if (($i % $batchSize) === 0) {
        $em->flush();
        $em->clear(); // Free up memory
    }
    $i++;
}

$em->flush();
$em->clear();

// Total: ~<?php echo ceil($flushCount / 20); ?> transactions instead of <?php echo $flushCount; ?></code></pre>
    </div>

    <p>By batching, you reduce the number of transactions from <?php echo $flushCount; ?> down to about <?php echo ceil($flushCount / 20); ?>. Each flush() creates a complete database transaction with all its overhead — query generation, index updates, locks, and commits.</p>

    <p>A few things to keep in mind:</p>
    <ul>
        <li>Use a batch size around 20-50 for inserts, smaller for updates</li>
        <li>Call clear() after flush() to prevent memory issues</li>
        <li>Don't forget to flush what's left after the loop</li>
    </ul>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/batch-processing.html" target="_blank" class="doc-link">
            Doctrine documentation on batch processing
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Batch processing recommended (%d flush calls found in loop)',
        $flushCount,
    ),
];
