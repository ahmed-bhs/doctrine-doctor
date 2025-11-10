<?php

declare(strict_types=1);

/**
 * Compact template for Flush in Loop suggestions.
 * @var int   $flush_count - Number of flush() calls detected
 * @var float $operations_between_flush - Average operations between flushes
 */

/** @var array<string, mixed> $context */
$flushCount = $context['flush_count'] ?? null;
$operationsBetweenFlush = $context['operations_between_flush'] ?? null;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-compact">
    <div class="suggestion-header">
        🔴 <strong>flush() in loop</strong> (<?php echo $flushCount; ?> calls)
    </div>

    <div class="suggestion-content">
        <pre><code class="language-php">// 📢 Current: <?php echo $flushCount; ?> flush()
assert(is_iterable($items), '$items must be iterable');

foreach ($items as $item) {
    $em->persist($entity);
    $em->flush(); // Every iteration!
}

// Solution: Batch of 20
$batch = 20;
assert(is_iterable($items), '$items must be iterable');

foreach ($items as $i => $item) {
    $em->persist($entity);
    if ($i % $batch === 0) {
        $em->flush();
        $em->clear();
    }
}
$em->flush();</code></pre>

        <p>
            <strong>⚡ Gain:</strong> ~<?php echo round((1 - (ceil($flushCount / 20) / $flushCount)) * 100); ?>% faster
            • <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/batch-processing.html" target="_blank">📖 Doc</a>
        </p>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('🔴 Batch processing required (%d flush detected)', $flushCount),
];
