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
<?php echo suggestionHeader('Check the decimal scale'); ?>
<div class="suggestion-content">
<p><?php echo $e((string) $description); ?> <?php echo $e((string) $infoMessage); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
private string $amount;</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#decimal', 'Doctrine decimal type docs'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Check that the decimal scale matches the currency',
];
