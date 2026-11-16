<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entity          = (string) ($context['entity'] ?? 'Entity');
$column          = (string) ($context['column'] ?? 'column_name');
$queryCount      = (int) ($context['query_count'] ?? 0);
$triggerLocation = (string) ($context['trigger_location'] ?? 'the calling code');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Load all values with one IN query'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo $queryCount; ?> queries looked up <code><?php echo $e($entity); ?></code> by <code><?php echo $e($column); ?></code>, one value at a time<?php if ('' !== $triggerLocation) { ?>, triggered at <code><?php echo $e($triggerLocation); ?></code><?php } ?>.
    Collecting the values first lets one query load them all.
</div>

<div class="query-item"><pre><code class="language-php">// Before: one query per value
foreach ($values as $value) {
    $entity = $repository-&gt;findOneBy(['<?php echo $e($column); ?>' =&gt; $value]);
}

// After: one query, indexed by <?php echo $e($column); ?>

$entities = $repository-&gt;createQueryBuilder('e', 'e.<?php echo $e($column); ?>')
    -&gt;where('e.<?php echo $e($column); ?> IN (:values)')
    -&gt;setParameter('values', $values)
    -&gt;getQuery()
    -&gt;getResult();
$entity = $entities[$value] ?? null;</code></pre></div>

<p>When the values are not known upfront, keep the lookups in an array cache so each value is queried at most once.</p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Load %s by %s with one IN query instead of %d lookups', $entity, $column, $queryCount),
];
