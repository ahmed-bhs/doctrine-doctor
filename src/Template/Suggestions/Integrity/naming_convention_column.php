<?php

declare(strict_types=1);

/**
 * Template for Column Naming Convention suggestions.
 * Context variables:
 * @var array<string, mixed> $context PHPStan: Template context
 */
$current   = (string) ($context['current'] ?? 'columnName');
$suggested = (string) ($context['suggested'] ?? 'column_name');
$fieldName = (string) ($context['field_name'] ?? 'field');
$severity  = (string) ($context['severity'] ?? 'info');

ob_start();
?>
<?php echo suggestionHeader('Fix Column Naming'); ?>
<div class="suggestion-content">
<?php echo suggestionAlert($severity, '<strong>Column naming violation:</strong> \'' . escape($current) . '\' should be \'' . escape($suggested) . '\''); ?>

<?php echo suggestionCodeBlock('Current', sprintf("#[ORM\\Column(name: '%s')]\nprivate \$%s;", $current, $fieldName)); ?>
<?php echo suggestionCodeBlock('Recommended', sprintf("#[ORM\\Column(name: '%s')]\nprivate \$%s;", $suggested, $fieldName)); ?>

<p><strong>Convention:</strong> snake_case, lowercase (first_name, created_at). Avoid SQL keywords.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/naming-strategy.html', 'Doctrine Naming Strategy Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf("Rename column from '%s' to '%s'", $current, $suggested),
];
