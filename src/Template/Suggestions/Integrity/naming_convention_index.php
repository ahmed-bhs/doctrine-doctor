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
<?php echo suggestionHeader('Rename the index'); ?>
<div class="suggestion-content">
<?php echo suggestionAlert($severity, t('Index <code>%s</code> should be named <code>%s</code>: <code>idx_</code> or <code>uniq_</code> followed by the column names in snake_case.', escape($current), escape($suggested))); ?>

<?php echo suggestionCodeBlock('', sprintf("// Before\n#[ORM\\Index(name: '%s', columns: ['...'])]\n\n// After\n#[ORM\\Index(name: '%s', columns: ['...'])]", $current, $suggested)); ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index', 'Doctrine Index Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t("Rename index from '%s' to '%s'", $current, $suggested),
];
