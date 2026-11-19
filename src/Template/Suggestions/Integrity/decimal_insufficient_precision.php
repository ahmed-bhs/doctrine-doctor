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
$description = tr($context['description'] ?? "Decimal precision insufficient for {$fieldName}");
$infoMessage = tr($context['info_message'] ?? "Current: ({$currentPrecision},{$currentScale}), Recommended: (20,10)");

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Increase the decimal precision'); ?>
<div class="suggestion-content">
<p><?php echo $e(rtrim((string) $description, '. ')); ?>. <?php echo $e((string) $infoMessage); ?>.</p>

<div class="query-item"><pre><code class="language-php">// Before
<?php echo $e((string) $badCode); ?>


// After
<?php echo $e((string) $goodCode); ?></code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#decimal', 'Doctrine Decimal Type Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Increase decimal precision to handle expected value ranges'),
];
