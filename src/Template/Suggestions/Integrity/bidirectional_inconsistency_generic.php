<?php

declare(strict_types=1);

/**
 * Template for Generic Bidirectional Inconsistency suggestion.
 * Context variables:
 * @var string $description - Description of the inconsistency
 * @var string $title - Title of the suggestion
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$description = $context['description'] ?? '';
$title = $context['title'] ?? null;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader((string) ($title ?? 'Align both sides of the association')); ?>
<div class="suggestion-content">
<p><?php echo $e($description); ?> Compare <code>cascade</code>, <code>orphanRemoval</code>, <code>nullable</code> and <code>onDelete</code> on both sides so the ORM and the database behave the same way.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html', 'Doctrine Associations Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Fix bidirectional association inconsistency',
];
