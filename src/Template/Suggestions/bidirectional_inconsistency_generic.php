<?php

declare(strict_types=1);

/**
 * Template for Generic Bidirectional Inconsistency suggestion.
 * Context variables:
 * @var string $description - Description of the inconsistency
 * @var string $title - Title of the suggestion
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$description = $context['description'] ?? '';
$title = $context['title'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4><?php echo $e($title); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Bidirectional association has inconsistent configuration</strong>
    </div>

    <p><?php echo $e($description); ?></p>

    <p>Check cascade settings, orphanRemoval, onDelete constraints, and nullable settings on both sides of the relationship. Make sure they're aligned.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html" target="_blank" class="doc-link">
            📖 Doctrine Associations Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Fix bidirectional association inconsistency',
];
