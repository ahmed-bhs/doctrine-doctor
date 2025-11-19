<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $table
 * @var mixed $operationCount
 * @var mixed $context
 */
['table' => $table, 'operation_count' => $operationCount] = $context;
$e                                                        = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="suggestion-header"><h4>Batch Processing Needed</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger">
<?php echo $operationCount; ?> operations without clear() will cause memory usage to grow indefinitely.</div>
<h4>Problem</h4>
<div class="query-item"><pre><code class="language-php">// Doctrine keeps ALL entities in memory
for ($i = 0; $i < <?php echo $operationCount; ?>; $i++) {
    $entity = $em->find(Entity::class, $i);
    $entity->process();
    $em->flush();
}
// Memory usage: <?php echo $operationCount; ?> * entity size!</code></pre></div>
<h4>Solution</h4>
<div class="query-item"><pre><code class="language-php">// Batch with clear() to free memory
$batchSize = 20;
for ($i = 0; $i < <?php echo $operationCount; ?>; $i++) {
    $entity = $em->find(Entity::class, $i);
    $entity->process();

    if (($i % $batchSize) === 0) {
        $em->flush();
        $em->clear();  // Frees memory!
    }
}
$em->flush();
// Memory usage: Only 20 * entity size at any time</code></pre></div>
<p><strong>Impact:</strong> Reduces memory from ~<?php echo round($operationCount / 20); ?>MB to ~1MB</p>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Use batch processing with clear() for %d operations', $operationCount)];
