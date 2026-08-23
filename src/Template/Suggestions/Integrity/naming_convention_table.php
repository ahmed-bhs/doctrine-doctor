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
<?php echo suggestionHeader('Fix Table Naming'); ?>
<div class="suggestion-content">
<?php echo suggestionAlert($severity, '<strong>Schema change</strong>' . "\n" . '<p>Table naming violation: \'' . escape($current) . '\' should be \'' . escape($suggested) . '\'</p>'); ?>

<?php echo suggestionCodeBlock('Current', sprintf("#[ORM\\Table(name: '%s')]\nclass %s {}", $current, $entityClass)); ?>
<?php echo suggestionCodeBlock('Recommended', sprintf("#[ORM\\Table(name: '%s')]\nclass %s {}", $suggested, $entityClass)); ?>

<p><strong>Convention:</strong> snake_case, singular (user, order_item). Avoid SQL reserved keywords. Generate a migration with <code>make:migration</code> after renaming.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/naming-strategy.html', 'Doctrine Naming Strategy Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf("Rename table from '%s' to '%s'", $current, $suggested),
];
