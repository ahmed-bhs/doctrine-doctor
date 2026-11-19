<?php

declare(strict_types=1);

/**
 * Template for Foreign Key Naming Convention suggestions.
 * Context variables:
 * @var array<string, mixed> $context PHPStan: Template context
 */
$current   = (string) ($context['current'] ?? 'fkColumn');
$suggested = (string) ($context['suggested'] ?? 'user_id');
$assocName = (string) ($context['assoc_name'] ?? 'user');
$severity  = (string) ($context['severity'] ?? 'info');

ob_start();
?>
<?php echo suggestionHeader('Rename the foreign key column'); ?>
<div class="suggestion-content">
<?php echo suggestionAlert($severity, t('Foreign key column <code>%s</code> should be named <code>%s</code>: snake_case with an <code>_id</code> suffix.', escape($current), escape($suggested))); ?>

<?php echo suggestionCodeBlock('', sprintf("// Before\n#[ORM\\JoinColumn(name: '%s')]\nprivate \$%s;\n\n// After\n#[ORM\\JoinColumn(name: '%s')]\nprivate \$%s;", $current, $assocName, $suggested, $assocName)); ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#joincolumn', 'Doctrine JoinColumn Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t("Rename foreign key from '%s' to '%s'", $current, $suggested),
];
