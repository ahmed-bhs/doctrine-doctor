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
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4><?php echo $e($title); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Bidirectional association has inconsistent configuration</strong>
    </div>

    <p><?php echo $e($description); ?></p>

    <h4>What to Check</h4>
    <ul>
        <li>Review cascade settings on both sides of the relationship</li>
        <li>Ensure orphanRemoval settings are consistent</li>
        <li>Check onDelete database-level constraints</li>
        <li>Verify nullable settings match the expected behavior</li>
    </ul>

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
