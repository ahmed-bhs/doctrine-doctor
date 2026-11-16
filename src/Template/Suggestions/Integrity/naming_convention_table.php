<?php

declare(strict_types=1);

/**
 * Template for Table Naming Convention suggestions.
 * Context variables:
 * @var array<string, mixed> $context PHPStan: Template context
 */
$current     = (string) ($context['current'] ?? 'TableName');
$suggested   = (string) ($context['suggested'] ?? 'table_name');
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$severity    = (string) ($context['severity'] ?? 'info');

ob_start();
?>
<?php echo suggestionHeader('Rename the table in snake_case'); ?>
<div class="suggestion-content">
<?php echo suggestionAlert($severity, 'Table <code>' . escape($current) . '</code> should be named <code>' . escape($suggested) . '</code>: lowercase snake_case, not an SQL reserved word. Generate a migration after renaming.'); ?>

<?php echo suggestionCodeBlock('', sprintf("// Before\n#[ORM\\Table(name: '%s')]\nclass %s {}\n\n// After\n#[ORM\\Table(name: '%s')]\nclass %s {}", $current, $entityClass, $suggested, $entityClass)); ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/naming-strategy.html', 'Doctrine Naming Strategy Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf("Rename table from '%s' to '%s'", $current, $suggested),
];
