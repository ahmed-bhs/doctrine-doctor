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
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Fix Decimal/Float Type Mismatch</h4>
</div>

<div class="suggestion-content">
    <p>The decimal type returns string from database but your property expects float. This causes performance issues and precision loss.</p>

    <div class="alert alert-danger">
        🚨 <?php echo $e($warningMessage); ?>
    </div>

    <h4>Choose Your Solution</h4>

    <?php foreach ($options as $index => $option) { ?>
    <div class="suggestion-option">
        <h4><?php echo $e($option['title']); ?></h4>
        <p><?php echo $e($option['description']); ?></p>

        <div class="query-item">
            <pre><code class="language-php"><?php echo $e($option['code']); ?></code></pre>
        </div>

        <?php if ([] !== $option['pros']) { ?>
        <div class="pros">
            <strong>Pros:</strong>
            <ul>
                <?php foreach ($option['pros'] as $pro) { ?>
                <li><?php echo $e($pro); ?></li>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>

        <?php if ([] !== $option['cons']) { ?>
        <div class="cons">
            <strong>📢 Cons:</strong>
            <ul>
                <?php foreach ($option['cons'] as $con) { ?>
                <li><?php echo $e($con); ?></li>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>
    </div>
    <?php } ?>

    <div class="alert alert-info">
        ℹ️ <?php echo $e($infoMessage); ?>
    </div>

    <p>
        <a href="<?php echo $e($moneyLibraryLink); ?>" target="_blank" class="doc-link">
            📖 Money PHP Library (moneyphp/money) →
        </a>
    </p>
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
