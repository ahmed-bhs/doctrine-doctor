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
$infoMessage = tr($context['info_message'] ?? '');

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
<?php echo suggestionHeader('Set precision and scale on the decimal column'); ?>
<div class="suggestion-content">
<p><?php echo t('The column has no explicit precision and scale, so each database applies its own default. %s', $e((string) $infoMessage)); ?></p>

<?php $option = $options[0] ?? ['title' => 'Standard Configuration', 'description' => 'Use (10,2) for typical decimal values', 'code' => '#[ORM\Column(type: "decimal", precision: 10, scale: 2)]']; ?>
<div class="query-item"><pre><code class="language-php">// <?php echo $e((string) ($option['description'] ?? '')); ?>

<?php echo $e((string) ($option['code'] ?? '')); ?></code></pre></div>

<p><?php echo t('Precision is the total number of digits, scale the digits after the decimal point.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#decimal', 'Doctrine decimal type docs'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Add explicit precision and scale to decimal columns'),
];
