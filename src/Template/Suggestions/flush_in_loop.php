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
        <?php echo $flushCount; ?> flush() calls = <?php echo $flushCount; ?> transactions instead of batched processing.
    </div>

    <h4>Solution: Batch processing</h4>
    <div class="query-item">
        <pre><code class="language-php">$batchSize = 20;
$i = 0;

foreach ($items as $item) {
    $entity = new Entity();
    $entity->setData($item);
    $em->persist($entity);

    if (($i % $batchSize) === 0) {
        $em->flush();
        $em->clear();
    }
    $i++;
}

$em->flush();
$em->clear();
// Result: ~<?php echo ceil($flushCount / 20); ?> transactions instead of <?php echo $flushCount; ?></code></pre>
    </div>

    <p>Batch size 20-50 for inserts. Always <code>clear()</code> after <code>flush()</code>.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/batch-processing.html" target="_blank" class="doc-link">
            📖 Doctrine batch processing →
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
