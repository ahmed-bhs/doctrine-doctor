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
<?php echo suggestionHeader('Rename the column in snake_case'); ?>
<div class="suggestion-content">
<?php echo suggestionAlert($severity, 'Column <code>' . escape($current) . '</code> should be named <code>' . escape($suggested) . '</code>. Lowercase snake_case names (<code>first_name</code>, <code>created_at</code>) need no quoting on any platform.'); ?>

<?php echo suggestionCodeBlock('', sprintf("// Before\n#[ORM\\Column(name: '%s')]\nprivate \$%s;\n\n// After\n#[ORM\\Column(name: '%s')]\nprivate \$%s;", $current, $fieldName, $suggested, $fieldName)); ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/naming-strategy.html', 'Doctrine Naming Strategy Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf("Rename column from '%s' to '%s'", $current, $suggested),
];
