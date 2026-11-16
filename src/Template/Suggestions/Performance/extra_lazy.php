<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entity          = (string) ($context['entity'] ?? 'Entity');
$relation        = (string) ($context['relation'] ?? 'items');
$queryCount      = (int) ($context['query_count'] ?? 0);
$hasLimit        = (bool) ($context['has_limit'] ?? false);
$triggerLocation = (string) ($context['trigger_location'] ?? 'the calling code');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$getter = 'get' . ucfirst($relation);

ob_start();
?>
<?php echo suggestionHeader($hasLimit ? 'Mark the collection EXTRA_LAZY' : 'Fetch-join the collection'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo $queryCount; ?> queries loaded the <code><?php echo $e($entity); ?>::$<?php echo $e($relation); ?></code> collection one parent at a time<?php if ('' !== $triggerLocation) { ?>, triggered at <code><?php echo $e($triggerLocation); ?></code><?php } ?>.
<?php if ($hasLimit) { ?>
    The code only reads part of it, so EXTRA_LAZY can answer <code>count()</code>, <code>slice()</code> and <code>contains()</code> without loading it.
<?php } else { ?>
    The loop reads every element, so loading the collection with its parents removes the extra queries.
<?php } ?>
</div>

<?php if ($hasLimit) { ?>
<div class="query-item"><pre><code class="language-php">#[ORM\OneToMany(targetEntity: Item::class, mappedBy: 'parent', fetch: 'EXTRA_LAZY')]
private Collection $<?php echo $e($relation); ?>;

// Each call now runs a small query instead of loading the collection
$entity-&gt;<?php echo $e($getter); ?>()-&gt;count();       // SELECT COUNT(*)
$entity-&gt;<?php echo $e($getter); ?>()-&gt;slice(0, 3);   // LIMIT 3</code></pre></div>

<p>If the loop ends up iterating every element, fetch-join the collection with <code>leftJoin()</code> and <code>addSelect()</code> instead.</p>
<?php } else { ?>
<div class="query-item"><pre><code class="language-php">$entities = $repository-&gt;createQueryBuilder('e')
    -&gt;leftJoin('e.<?php echo $e($relation); ?>', 'r')
    -&gt;addSelect('r')
    -&gt;getQuery()
    -&gt;getResult();
// 1 query instead of <?php echo $queryCount; ?></code></pre></div>

<p>If the loop only counts or checks the collection, map it with <code>fetch: 'EXTRA_LAZY'</code> instead.</p>
<?php } ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/extra-lazy-associations.html', 'Doctrine Extra Lazy Associations Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        $hasLimit ? 'Use EXTRA_LAZY on %s.%s for partial access' : 'Fetch-join %s.%s instead of loading it per entity',
        $entity,
        $relation,
    ),
];
