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
$description = tr($context['description'] ?? t('Decimal precision may be excessive for %s', $fieldName));
$precisionNeeds = $context['precision_needs'] ?? [t('Consider reducing to (10,2) for typical use cases')];

// Ensure precision_needs is an array
if (!is_array($precisionNeeds)) {
    $precisionNeeds = [$precisionNeeds];
}

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Reduce the decimal precision'); ?>
<div class="suggestion-content">
<p><?php echo t('%s Typical ranges: %s.', $e((string) $description), $e(implode('; ', array_map(tr(...), $precisionNeeds)))); ?></p>

<div class="query-item"><pre><code class="language-php">// Money, for example: up to 99 999 999.99
#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#decimal', 'Doctrine Decimal Type'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Reduce decimal precision to the range the values need'),
];
