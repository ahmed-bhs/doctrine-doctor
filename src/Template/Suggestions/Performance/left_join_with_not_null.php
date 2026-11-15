<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$field  = (string) ($context['field'] ?? 'created_at');
$entity = (string) ($context['entity'] ?? 'Entity');
$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Use INNER JOIN on a NOT NULL field'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>">
    The query LEFT JOINs <code><?= $e($field) ?></code>, which is NOT NULL, so the join never yields a NULL row.
    An INNER JOIN returns the same rows and gives the planner more room.
</div>

<div class="query-item"><pre><code class="language-php">// Before
$qb-&gt;from(<?= $e($entity) ?>::class, 'e')-&gt;leftJoin('e.<?= $e($field) ?>', 'f');

// After
$qb-&gt;from(<?= $e($entity) ?>::class, 'e')-&gt;innerJoin('e.<?= $e($field) ?>', 'f');</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#joins', 'Doctrine ORM Joins'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use INNER JOIN instead of LEFT JOIN on a NOT NULL field',
];
