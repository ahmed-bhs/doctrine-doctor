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

ob_start();
?>

<div class="suggestion-header">
    <h4>Check array before using IN()</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
         <strong>An empty IN() clause will cause a SQL syntax error at runtime.</strong>
    </div>

    <p>Always validate that the array is not empty before using IN() clause.</p>

    <?php $option = $options[0] ?? ['title' => 'Early Return Pattern', 'description' => 'Check for empty array before building query', 'code' => 'if (empty($ids)) { return []; }']; ?>
    <h4><?php echo $e($option['title'] ?? 'Solution'); ?></h4>
    <p><?php echo $e($option['description'] ?? ''); ?></p>

    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($option['code'] ?? ''); ?></code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/query-builder.html" target="_blank" class="doc-link">
            📖 Doctrine QueryBuilder Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Check array before using IN() clause to prevent SQL syntax errors',
];
