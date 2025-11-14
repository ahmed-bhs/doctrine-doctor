<?php

declare(strict_types=1);

/**
 * Template for Decimal Excessive Precision suggestion.
 * Context variables:
 * @var string       $description - Description of the issue
 * @var array<mixed> $precision_needs - List of typical precision needs
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$fieldName = $context['field_name'] ?? 'field';
$currentPrecision = $context['current_precision'] ?? 20;
$currentScale = $context['current_scale'] ?? 10;
$description = $context['description'] ?? "Decimal precision may be excessive for {$fieldName}";
$precisionNeeds = $context['precision_needs'] ?? ["Consider reducing to (10,2) for typical use cases"];

// Ensure precision_needs is an array
if (!is_array($precisionNeeds)) {
    $precisionNeeds = [$precisionNeeds];
}

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
    </svg>
    <h4>Consider Reducing Precision</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Precision (<?php echo $currentPrecision; ?>,<?php echo $currentScale; ?>) may be excessive. Most cases need 10-20.
    </div>

    <h4>Impact: More storage, slower operations, larger indexes</h4>

    <p>Typical needs: Money (10,2), Scientific (15,6), Crypto (20,8). Consider reducing if excessive.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#decimal" target="_blank" class="doc-link">
            📖 Doctrine Decimal Type →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Consider reducing precision to improve storage efficiency and performance',
];
