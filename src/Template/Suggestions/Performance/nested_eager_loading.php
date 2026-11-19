<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entities = $context['entities'] ?? ['Entity', 'Relation'];
if (!is_array($entities) || [] === $entities) {
    $entities = ['Entity', 'Relation'];
}
$entities = array_values(array_map(static fn (mixed $entity): string => (string) $entity, $entities));
$depth = max(1, (int) ($context['depth'] ?? max(1, count($entities) - 1)));
$queryCount = max(0, (int) ($context['query_count'] ?? 0));
$chain = (string) ($context['chain'] ?? implode(' -> ', $entities));

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// One alias per level: first letter of the entity plus its level
$aliases = [];
foreach ($entities as $level => $name) {
    $aliases[$level] = strtolower('' === $name ? 'e' : $name[0]) . $level;
}

ob_start();
?>
<?php echo suggestionHeader('Fetch-join the whole relation chain'); ?>
<div class="suggestion-content">
<div class="alert alert-danger">
    <?php echo t('%d queries ran across the %d-level chain <code>%s</code>, one per entity and level.', $queryCount, $depth, $e($chain)); ?>
    <?php echo t('Joining every level with <code>addSelect()</code> loads the chain in one query.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">// In <?php echo $e($entities[0]); ?>Repository
return $this-&gt;createQueryBuilder('<?php echo $e($aliases[0]); ?>')
<?php foreach (array_slice($entities, 1, null, true) as $level => $name) { ?>
    -&gt;leftJoin('<?php echo $e($aliases[$level - 1]); ?>.<?php echo $e(lcfirst($name)); ?>', '<?php echo $e($aliases[$level]); ?>')
    -&gt;addSelect('<?php echo $e($aliases[$level]); ?>')
<?php } ?>
    -&gt;getQuery()
    -&gt;getResult();</code></pre></div>

<p><?php echo t('For read-only output, select only the displayed columns into a DTO with <code>SELECT NEW</code>.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins', 'Doctrine Multi-Level JOIN Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Fetch-join the %d-level chain %s to avoid %d nested N+1 queries', $depth, $chain, $queryCount),
];
