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
$warningMessage = tr($context['warning_message'] ?? '');
$infoMessage = tr($context['info_message'] ?? '');
$moneyLibraryLink = $context['money_library_link'] ?? null;
$doctrineTypesLink = $context['doctrine_types_link'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Type decimal properties as string'); ?>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <?php echo t('%s Doctrine hydrates <code>decimal</code> columns as strings, so a <code>float</code> property loses precision and differs from the loaded value on every flush.', $e($warningMessage)); ?>
    </div>

    <?php $option = $options[0] ?? ['title' => 'Change type hint', 'description' => 'Use string type hint', 'code' => 'private string $field;']; ?>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($option['code']); ?></code></pre>
    </div>

    <p><?php echo $e(tr($option['description'])); ?> <?php echo $e($infoMessage); ?></p>

    <?php if ($doctrineTypesLink) { ?><?php echo suggestionDocLink((string) $doctrineTypesLink, 'Doctrine Mapping Types Reference'); ?><?php } ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Type the decimal property as string instead of float'),
];
