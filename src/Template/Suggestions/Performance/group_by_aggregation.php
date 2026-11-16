<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entity = (string) ($context['entity'] ?? 'Entity');
$relation = (string) ($context['relation'] ?? 'items');
$queryCount = max(0, (int) ($context['query_count'] ?? 0));

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$countAlias = lcfirst($relation) . 'Count';

ob_start();
?>
<?php echo suggestionHeader('Count in SQL with GROUP BY'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo $queryCount; ?> queries loaded <code><?php echo $e($relation); ?></code> only to count it.
    One GROUP BY query returns every count at once.
</div>

<div class="query-item"><pre><code class="language-php">// Before: one query per <?php echo $e($entity); ?>

foreach ($repository-&gt;findAll() as $entity) {
    $count = $entity-&gt;get<?php echo $e(ucfirst($relation)); ?>()-&gt;count();
}

// After: one query
$rows = $repository-&gt;createQueryBuilder('e')
    -&gt;select('e.id', 'e.title', 'COUNT(r.id) AS <?php echo $e($countAlias); ?>')
    -&gt;leftJoin('e.<?php echo $e($relation); ?>', 'r')
    -&gt;groupBy('e.id')
    -&gt;getQuery()
    -&gt;getArrayResult();</code></pre></div>

<p>To keep the entities, select <code>e, COUNT(r.id) AS <?php echo $e($countAlias); ?></code>: each row is then <code>[0 =&gt; $entity, '<?php echo $e($countAlias); ?>' =&gt; $count]</code>.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#grouping', 'Doctrine DQL GROUP BY Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Count %s with one GROUP BY query instead of one query per entity', $relation),
];
