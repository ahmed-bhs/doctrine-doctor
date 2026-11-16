<?php

declare(strict_types=1);

/**
 * Template for Flush in Loop suggestions.
 * Context variables:
 * @var int   $flush_count - Number of flush() calls detected
 * @var float $operations_between_flush - Average operations between flushes
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$flushCount = $context['flush_count'] ?? null;
$operationsBetweenFlush = $context['operations_between_flush'] ?? null;

$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$flushes = (int) $flushCount;

ob_start();
?>
<?php echo suggestionHeader('Flush in batches, not in the loop'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>">
    flush() ran <?php echo $flushes; ?> times inside a loop, each call computing the change set and opening a transaction.
    Flushing every 20 entities would need about <?php echo (int) ceil($flushes / 20); ?>.
</div>

<div class="query-item"><pre><code class="language-php">foreach ($items as $i =&gt; $item) {
    $entity = new Entity();
    $entity-&gt;setData($item);
    $em-&gt;persist($entity);

    if (0 === ($i + 1) % 20) {
        $em-&gt;flush();
        $em-&gt;clear(); // detaches the processed entities
    }
}
$em-&gt;flush();</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/batch-processing.html', 'Doctrine batch processing'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Flush in batches instead of %d times in a loop', $flushes),
];
