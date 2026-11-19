<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$parentEntity    = (string) ($context['parent_entity'] ?? 'Entity');
$collectionField = (string) ($context['collection_field'] ?? 'items');
$childEntity     = (string) ($context['child_entity'] ?? 'RelatedEntity');
$queryCount      = (int) ($context['query_count'] ?? 0);
$triggerLocation = (string) ($context['trigger_location'] ?? t('the calling code'));
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

$lcCollectionField = lcfirst($collectionField);
$alias = substr($lcCollectionField, 0, 1);

ob_start();
?>
<?php echo suggestionHeader('Fetch-join the collection'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo t('%d queries loaded <code>%s::$%s</code> (%s) one parent at a time%s.', $queryCount, $e($parentEntity), $e($collectionField), $e($childEntity), '' === $triggerLocation ? '' : t(', triggered at <code>%s</code>', $e($triggerLocation))); ?>
    <?php echo t('Selecting the joined collection loads it with the parents.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">$entities = $repository-&gt;createQueryBuilder('e')
    -&gt;leftJoin('e.<?php echo $e($lcCollectionField); ?>', '<?php echo $e($alias); ?>')
    -&gt;addSelect('<?php echo $e($alias); ?>')
    -&gt;getQuery()
    -&gt;getResult();
// 1 query instead of <?php echo $queryCount; ?></code></pre></div>

<p><?php echo t("If the code only calls <code>count()</code>, <code>contains()</code> or <code>slice()</code>, map the collection with <code>fetch: 'EXTRA_LAZY'</code> instead."); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins', 'Doctrine DQL Joins Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Fetch-join %s::$%s with addSelect(), or map it EXTRA_LAZY', $parentEntity, $collectionField),
];
