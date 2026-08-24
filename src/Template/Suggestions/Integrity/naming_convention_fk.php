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
<?php echo suggestionHeader('Fix Foreign Key Naming'); ?>
<div class="suggestion-content">
<?php echo suggestionAlert($severity, '<strong>FK naming violation:</strong> \'' . escape($current) . '\' should be \'' . escape($suggested) . '\''); ?>

<?php echo suggestionCodeBlock('Current', sprintf("#[ORM\\JoinColumn(name: '%s')]\nprivate \$%s;", $current, $assocName)); ?>
<?php echo suggestionCodeBlock('Recommended', sprintf("#[ORM\\JoinColumn(name: '%s')]\nprivate \$%s;", $suggested, $assocName)); ?>

<p><strong>Convention:</strong> snake_case with _id suffix (user_id, product_id).</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#joincolumn', 'Doctrine JoinColumn Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf("Rename foreign key from '%s' to '%s'", $current, $suggested),
];
