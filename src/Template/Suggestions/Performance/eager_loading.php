<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entity          = (string) ($context['entity'] ?? 'Entity');
$relation        = (string) ($context['relation'] ?? 'items');
$queryCount      = (int) ($context['query_count'] ?? 0);
$triggerLocation = (string) ($context['trigger_location'] ?? t('the calling code'));
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Fetch-join the relation in the query'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo t('%d queries loaded <code>%s</code> one entity at a time%s.', $queryCount, $e($relation), '' === $triggerLocation ? '' : t(', triggered at <code>%s</code>', $e($triggerLocation))); ?>
    <?php echo t('Joining it with <code>addSelect()</code> loads it together with the parents.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">$entities = $repository-&gt;createQueryBuilder('e')
    -&gt;leftJoin('e.<?php echo $e($relation); ?>', 'r')
    -&gt;addSelect('r')
    -&gt;getQuery()
    -&gt;getResult();
// One LEFT JOIN query instead of <?php echo $queryCount; ?></code></pre></div>

<p><?php echo t("Do not set <code>fetch: 'EAGER'</code> on the mapping for this: it applies to every query on the entity."); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins', 'Doctrine DQL joins'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Eager load the %s relation with a fetch join instead of one query per entity', $relation),
];
