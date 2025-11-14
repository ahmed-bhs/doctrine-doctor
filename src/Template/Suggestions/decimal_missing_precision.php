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
    <h4>Add Explicit Precision/Scale</h4>
</div>

<div class="suggestion-content">
    <p>Always specify precision and scale for decimal columns to ensure consistent behavior across databases.</p>

    <?php $option = $options[0] ?? ['title' => 'Standard Configuration', 'description' => 'Use (10,2) for typical decimal values', 'code' => '#[ORM\Column(type: "decimal", precision: 10, scale: 2)]']; ?>
    <h4><?php echo $e($option['title'] ?? 'Solution'); ?></h4>
    <pre><code class="language-php"><?php echo $e($option['code'] ?? ''); ?></code></pre>

    <p><strong>Precision</strong> is total digits, <strong>scale</strong> is digits after decimal. <?php echo $e($infoMessage); ?></p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#decimal" target="_blank" class="doc-link">
            📖 Doctrine decimal type docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add explicit precision and scale to decimal columns',
];
