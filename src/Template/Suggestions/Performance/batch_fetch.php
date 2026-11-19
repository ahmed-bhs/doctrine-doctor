<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entity          = (string) ($context['entity'] ?? 'Entity');
$relation        = (string) ($context['relation'] ?? 'items');
$field           = '' === $relation ? 'relation' : $relation;
$queryCount      = (int) ($context['query_count'] ?? 0);
$triggerLocation = (string) ($context['trigger_location'] ?? t('the calling code'));
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Fetch-join the to-one relation'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo t('%d queries initialized %s proxies one by one inside a loop%s.', $queryCount, '<code>' . $e($entity) . ('' === $relation ? '' : '::$' . $e($relation)) . '</code>', '' === $triggerLocation ? '' : t(', triggered at <code>%s</code>', $e($triggerLocation))); ?>
    <?php echo t('A fetch join loads the related entities with their parents.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">// Before: one query per proxy
foreach ($repository-&gt;findAll() as $entity) {
    $entity-&gt;get<?php echo $e(ucfirst($field)); ?>()-&gt;getName();
}

// After: 1 query instead of <?php echo $queryCount; ?>

$entities = $repository-&gt;createQueryBuilder('e')
    -&gt;leftJoin('e.<?php echo $e($field); ?>', 'r')
    -&gt;addSelect('r')
    -&gt;getQuery()
    -&gt;getResult();</code></pre></div>

<p><?php echo t('Without <code>addSelect()</code>, the JOIN only filters and the proxies stay uninitialized.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins', 'Doctrine DQL Joins Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Fetch-join %s.%s (join + addSelect) to load it in a single query', $entity, $relation),
];
