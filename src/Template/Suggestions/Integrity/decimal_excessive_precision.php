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
