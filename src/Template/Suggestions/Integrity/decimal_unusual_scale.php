<?php

declare(strict_types=1);

/**
 * Template for Decimal Unusual Scale suggestion.
 * Context variables:
 * @var string       $description - Description of the issue
 * @var array<mixed> $currency_scales - List of common currency scales
 * @var string       $info_message - Additional info message
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$description = $context['description'] ?? '';
$currencyScales = $context['currency_scales'] ?? null;
$infoMessage = $context['info_message'] ?? '';

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Review Decimal Scale</h4>
</div>

<div class="suggestion-content">
    <p><?php echo $e($description); ?></p>

    <p>Most currencies use 2 decimal places. Some like BTC use more, but an unusual scale might indicate a misconfiguration.</p>

    <?php if ($infoMessage) { ?>
    <div class="alert alert-info">
        <?php echo $e($infoMessage); ?>
    </div>
    <?php } ?>

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
    'description' => 'Review decimal scale to ensure it matches your currency requirements',
];
