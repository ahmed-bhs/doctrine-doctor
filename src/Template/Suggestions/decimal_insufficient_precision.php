<?php

declare(strict_types=1);

/**
 * Template for Decimal Insufficient Precision suggestion.
 * Context variables:
 * @var string $bad_code - Example of incorrect code
 * @var string $good_code - Example of correct code
 * @var string $description - Description of the issue
 * @var string $info_message - Additional info about precision
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$entityClass = $context['entity_class'] ?? 'Entity';
$fieldName = $context['field_name'] ?? 'field';
$currentPrecision = $context['current_precision'] ?? 10;
$currentScale = $context['current_scale'] ?? 2;

// Generate code examples
$badCode = $context['bad_code'] ?? "#[ORM\Column(type: 'decimal', precision: {$currentPrecision}, scale: {$currentScale})]";
$goodCode = $context['good_code'] ?? "#[ORM\Column(type: 'decimal', precision: 20, scale: 10)]";
$description = $context['description'] ?? "Decimal precision insufficient for {$fieldName}";
$infoMessage = $context['info_message'] ?? "Current: ({$currentPrecision},{$currentScale}), Recommended: (20,10)";

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Increase Decimal Precision</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <?php echo $e($description); ?>
    </div>

    <h4>Current (insufficient)</h4>
    <pre><code class="language-php"><?php echo $e($badCode); ?></code></pre>

    <h4>Recommended</h4>
    <pre><code class="language-php"><?php echo $e($goodCode); ?></code></pre>

    <p><?php echo $e($infoMessage); ?> - Prevents data truncation and runtime errors.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#decimal" target="_blank" class="doc-link">
            📖 Doctrine Decimal Type Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Increase decimal precision to handle expected value ranges',
];
