<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entity
 * @var mixed $column
 * @var int $queryCount
 * @var mixed $triggerLocation
 * @var array<string, mixed> $context
 */
$entity          = (string) ($context['entity'] ?? 'Entity');
$column          = (string) ($context['column'] ?? 'column_name');
$queryCount      = (int) ($context['query_count'] ?? 0);
$triggerLocation = (string) ($context['trigger_location'] ?? 'the calling code');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<?php echo suggestionHeader('Repeated Lookup Query Problem'); ?>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Repeated Lookup Detected</strong><br>
        Detected <strong><?php echo $queryCount; ?> sequential queries</strong> looking up <code><?php echo $e($entity); ?></code> by <code><?php echo $e($column); ?></code>.
        This happens when calling <code>findBy()</code> or <code>findOneBy()</code> repeatedly with different values for the same column.
    </div>

<?php if ('' !== $triggerLocation) { ?>
    <div class="alert alert-info">
        <strong>Triggered at:</strong> <code><?php echo $e($triggerLocation); ?></code>
    </div>
<?php } ?>

    <h4>Problem: Repeated findBy/findOneBy in Loop</h4>
    <div class="query-item">
        <pre><code class="language-php">foreach ($values as $value) {
    $entity = $repository->findOneBy(['<?php echo $e($column); ?>' => $value]); // Query triggered here!
}
// Result: <?php echo $queryCount; ?> queries instead of 1</code></pre>
    </div>

    <h4>Solution 1: Batch Load with IN Query</h4>
    <div class="query-item">
        <pre><code class="language-php">$entities = $repository->createQueryBuilder('e')
    ->where('e.<?php echo $e($column); ?> IN (:values)')
    ->setParameter('values', $allValues)
    ->getQuery()
    ->getResult();

// Index by <?php echo $e($column); ?> for O(1) access
$indexed = [];
foreach ($entities as $entity) {
    $indexed[$entity->get<?php echo $e(ucfirst((string) $column)); ?>()] = $entity;
}
// Result: 1 query instead of <?php echo $queryCount; ?></code></pre>
    </div>

    <h4>Solution 2: In-Memory Cache</h4>
    <div class="query-item">
        <pre><code class="language-php">private array $cache = [];

public function getBy<?php echo $e(ucfirst((string) $column)); ?>(string $<?php echo $e($column); ?>): ?<?php echo $e($entity); ?>

{
    if (array_key_exists($<?php echo $e($column); ?>, $this->cache)) {
        return $this->cache[$<?php echo $e($column); ?>];
    }

    $entity = $this->repository->findOneBy(['<?php echo $e($column); ?>' => $<?php echo $e($column); ?>]);
    $this->cache[$<?php echo $e($column); ?>] = $entity;

    return $entity;
}</code></pre>
    </div>

    <div class="alert alert-info">
        <strong>Expected improvement:</strong><br>
        <ul>
            <li><strong>Current:</strong> <?php echo $queryCount; ?> queries (one per lookup value)</li>
            <li><strong>With IN query:</strong> 1 query total</li>
            <li><strong>With cache:</strong> max 1 query per unique value</li>
        </ul>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Repeated lookup on %s.%s - batch with IN query or add in-memory cache',
        $entity,
        $column,
    ),
];
