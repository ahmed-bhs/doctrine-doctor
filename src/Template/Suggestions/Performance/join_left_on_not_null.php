<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$table = (string) ($context['table'] ?? 'related_table');
$alias = (string) ($context['alias'] ?? 'r');
$entity = (string) ($context['entity'] ?? 'Entity');
$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Use INNER JOIN on a mandatory relation'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>">
    <?php echo t('The LEFT JOIN on <code>%s</code> follows a NOT NULL foreign key, so it never produces a NULL row.', $e($table)); ?>
    <?php echo t('An INNER JOIN returns the same rows and leaves the planner free to choose the join order.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">// Before
$qb-&gt;leftJoin('o.relation', '<?php echo $e($alias); ?>');

// After
$qb-&gt;innerJoin('o.relation', '<?php echo $e($alias); ?>');</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#joins', 'Doctrine ORM Joins'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Use INNER JOIN instead of LEFT JOIN on a NOT NULL relation'),
];
