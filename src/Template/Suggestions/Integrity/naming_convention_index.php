<?php

declare(strict_types=1);

/**
 * Template for Index Naming Convention suggestions.
 * Context variables:
 * @var array<string, mixed> $context PHPStan: Template context
 */
$current   = (string) ($context['current'] ?? 'indexName');
$suggested = (string) ($context['suggested'] ?? 'idx_column');
$severity  = (string) ($context['severity'] ?? 'info');

ob_start();
?>
<?php echo suggestionHeader('Fix Index Naming Convention'); ?>
<div class="suggestion-content">
<?php echo suggestionAlert($severity, '<strong>Index naming convention violation detected.</strong>'); ?>

<?php echo suggestionCodeBlock('Current', sprintf("#[ORM\\Index(name: '%s', columns: ['...'])]", $current)); ?>
<?php echo suggestionCodeBlock('Recommended', sprintf("#[ORM\\Index(name: '%s', columns: ['...'])]", $suggested)); ?>

<h4>Index/Constraint conventions</h4>
<ul>
    <li>Regular indexes: idx_{columns} (idx_email, idx_status_created_at)</li>
    <li>Unique constraints: uniq_{columns} (uniq_email, uniq_username)</li>
    <li>Use snake_case</li>
    <li>Include column names in index name for clarity</li>
</ul>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index', 'Doctrine Index Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf("Rename index from '%s' to '%s'", $current, $suggested),
];
