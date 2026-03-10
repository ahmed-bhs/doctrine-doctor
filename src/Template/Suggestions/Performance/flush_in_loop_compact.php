<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$flushCount = max(0, (int) ($context['flush_count'] ?? 0));
$operationsBetweenFlush = max(0, (int) ($context['operations_between_flush'] ?? 20));
$estimatedGain = $flushCount > 0 ? round((1 - (ceil($flushCount / 20) / $flushCount)) * 100) : 0;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>flush() in loop (<?php echo $flushCount; ?> calls)</h4></div>
<div class="suggestion-content">
<div class="query-item"><pre><code class="language-php">// Current: <?php echo $flushCount; ?> flush()
foreach ($items as $item) {
    $em->persist($entity);
    $em->flush(); // Every iteration
}

// Better: batch of 20
$batch = 20;
foreach ($items as $i => $item) {
    $em->persist($entity);
    if ($i % $batch === 0) {
        $em->flush();
        $em->clear();
    }
}
$em->flush();</code></pre></div>

<p>
    ~<?php echo $estimatedGain; ?>% faster
    | <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/batch-processing.html" target="_blank">Docs</a>
</p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Use batch processing (%d flush() calls in a loop)', $flushCount),
];
