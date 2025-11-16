<?php

declare(strict_types=1);

/**
 * Template for Decimal/Float Type Hint Mismatch suggestion.
 * Context variables:
 * @var array<mixed> $options - Array of options with title, description, code, pros, cons
 * @var string       $warning_message - Warning about float for money
 * @var string       $info_message - Info about unnecessary UPDATEs
 * @var string       $money_library_link - Link to Money PHP library
 * @var string       $doctrine_types_link - Link to Doctrine types reference
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$options = $context['options'] ?? null;
$warningMessage = $context['warning_message'] ?? '';
$infoMessage = $context['info_message'] ?? '';
$moneyLibraryLink = $context['money_library_link'] ?? null;
$doctrineTypesLink = $context['doctrine_types_link'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Fix Decimal/Float Type Mismatch</h4>
</div>

<div class="suggestion-content">
    <p>The decimal type returns string from database but your property expects float. This causes performance issues and precision loss.</p>

    <div class="alert alert-danger">
         <?php echo $e($warningMessage); ?>
    </div>

    <h4>Solution: Change type hint to string</h4>
    <?php $option = $options[0] ?? ['title' => 'Change type hint', 'description' => 'Use string type hint', 'code' => 'private string $field;']; ?>
    <p><?php echo $e($option['description']); ?></p>

    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($option['code']); ?></code></pre>
    </div>

    <p><?php echo $e($infoMessage); ?></p>

    <p>
        <a href="<?php echo $e($doctrineTypesLink); ?>" target="_blank" class="doc-link">
            📖 Doctrine Mapping Types Reference →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Fix decimal/float type mismatch to prevent performance issues',
];
