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
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
    </svg>
    <h4>Increase Decimal Precision</h4>
</div>

<div class="suggestion-content">
    <p><?php echo $e($description); ?></p>

    <h4>Current Configuration (Insufficient)</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($badCode); ?></code></pre>
    </div>

    <h4>Recommended Configuration</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($goodCode); ?></code></pre>
    </div>

    <?php if (isset($infoMessage)) { ?>
    <div class="alert alert-info">
        ℹ️ <?php echo $e($infoMessage); ?>
    </div>
    <?php } ?>

    <h4>Why This Matters</h4>
    <ul>
        <li>Prevents data truncation and loss</li>
        <li>Ensures you can store values within expected range</li>
        <li>Matches business requirements for precision</li>
        <li>Avoids runtime errors when inserting large values</li>
    </ul>

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
