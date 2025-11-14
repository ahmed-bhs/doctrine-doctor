<?php

declare(strict_types=1);

/**
 * Template for Decimal Missing Precision suggestion.
 * Context variables:
 * @var array<mixed> $options - Array of options with title, description, code
 * @var array<mixed> $understanding_points - List of understanding points
 * @var string       $info_message - Additional info message
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$options = $context['options'] ?? [
    ['title' => 'Standard Configuration', 'description' => 'Use (10,2) for typical decimal values', 'code' => '#[ORM\Column(type: "decimal", precision: 10, scale: 2)]'],
];
$understandingPoints = $context['understanding_points'] ?? [
    'Precision: Total number of digits',
    'Scale: Number of digits after decimal point',
];
$infoMessage = $context['info_message'] ?? '';

// Ensure options is an array with expected structure
if (!is_array($options) || (isset($options[0]) && !is_array($options[0]))) {
    $options = [
        ['title' => 'Standard Configuration', 'description' => 'Use (10,2) for typical decimal values', 'code' => '#[ORM\Column(type: "decimal", precision: 10, scale: 2)]'],
    ];
}

// Ensure understandingPoints is an array
if (!is_array($understandingPoints)) {
    $understandingPoints = [$understandingPoints];
}

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Add Explicit Precision/Scale</h4>
</div>

<div class="suggestion-content">
    <p>Always specify precision and scale for decimal columns to ensure consistent behavior across databases.</p>

    <h4>Configuration options</h4>

    <?php foreach ($options as $index => $option) { ?>
    <div class="suggestion-option">
        <h4><?php echo $e($option['title'] ?? 'Configuration'); ?></h4>
        <p><?php echo $e($option['description'] ?? ''); ?></p>

        <div class="query-item">
            <pre><code class="language-php"><?php echo $e($option['code'] ?? ''); ?></code></pre>
        </div>
    </div>
    <?php } ?>

    <p><strong>Precision</strong> is the total number of digits, and <strong>scale</strong> is how many go after the decimal point. Without specifying these, different databases may handle your decimals differently.</p>

    <?php if ($infoMessage) { ?>
    <div class="alert alert-info">
        <?php echo $e($infoMessage); ?>
    </div>
    <?php } ?>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#decimal" target="_blank" class="doc-link">
            Doctrine decimal type docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add explicit precision and scale to decimal columns',
];
