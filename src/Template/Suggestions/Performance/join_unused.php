<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$type = (string) ($context['type'] ?? 'LEFT');
$table = (string) ($context['table'] ?? 'related_table');
$alias = (string) ($context['alias'] ?? 'r');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Remove the unused JOIN'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo t('The %s JOIN on <code>%s</code> (alias <code>%s</code>) is neither selected nor used in a condition.', $e($type), $e($table), $e($alias)); ?>
    <?php echo t('The database still performs it for every row.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">// Remove this line if the relation is not read
-&gt;leftJoin('o.relation', '<?php echo $e($alias); ?>')

// Or select it to preload the relation
-&gt;leftJoin('o.relation', '<?php echo $e($alias); ?>')
-&gt;addSelect('<?php echo $e($alias); ?>')</code></pre></div>

<p><?php echo t('An INNER JOIN also filters out rows without a match: keep it if that filter is intended.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#joins', 'Doctrine ORM Joins'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Remove the unused JOIN or add its alias to the SELECT'),
];
