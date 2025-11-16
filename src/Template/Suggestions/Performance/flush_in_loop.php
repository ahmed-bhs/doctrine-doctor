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
    <h4>flush() in loop</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <?php echo $flushCount; ?> flush() calls = <?php echo $flushCount; ?> separate transactions
    </div>

    <h4>Batch processing</h4>
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
// ~<?php echo ceil($flushCount / 20); ?> transactions instead of <?php echo $flushCount; ?></code></pre>
    </div>

    <p>Use batch size 20-50. Always call <code>clear()</code> after <code>flush()</code>.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/batch-processing.html" target="_blank" class="doc-link">
            📖 Doctrine batch processing
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
