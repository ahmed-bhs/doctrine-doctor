<?php

declare(strict_types=1);

/**
 * Template for Empty IN() Clause suggestion.
 * Context variables:
 * @var array<mixed> $options - Array of options with title, description, code, pros, cons
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$options = $context['options'] ?? [
    ['title' => 'Early Return Pattern', 'description' => 'Check for empty array before building query', 'code' => 'if (empty($ids)) { return []; }', 'pros' => ['Clean and simple'], 'cons' => []],
];

// Ensure options is an array with expected structure
if (!is_array($options) || (isset($options[0]) && !is_array($options[0]))) {
    $options = [
        ['title' => 'Early Return Pattern', 'description' => 'Check for empty array before building query', 'code' => 'if (empty($ids)) { return []; }', 'pros' => ['Clean and simple'], 'cons' => []],
    ];
}

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$option = $options[0] ?? ['title' => 'Early Return Pattern', 'description' => 'Check for empty array before building query', 'code' => 'if (empty($ids)) { return []; }'];

ob_start();
?>
<?php echo suggestionHeader('Check the array before using IN()'); ?>
<div class="suggestion-content">
<p><?php echo t('An empty array bound to <code>IN()</code> produces <code>IN ()</code>, which is a SQL syntax error. %s', $e(tr($option['description'] ?? ''))); ?></p>

<div class="query-item"><pre><code class="language-php"><?php echo $e((string) ($option['code'] ?? '')); ?></code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/query-builder.html', 'Doctrine QueryBuilder Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Check the array is not empty before using IN()'),
];
