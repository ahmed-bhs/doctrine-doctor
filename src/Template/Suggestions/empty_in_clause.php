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
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Check array before using IN()</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        🚨 <strong>An empty IN() clause will cause a SQL syntax error at runtime.</strong>
    </div>

    <p>Always validate that the array is not empty before using IN() clause. Here are several approaches:</p>

    <?php foreach ($options as $index => $option) { ?>
    <div class="suggestion-option">
        <h4><?php echo $e($option['title'] ?? 'Option'); ?></h4>
        <p><?php echo $e($option['description'] ?? ''); ?></p>

        <div class="query-item">
            <pre><code class="language-php"><?php echo $e($option['code'] ?? ''); ?></code></pre>
        </div>

        <?php if (isset($option['pros']) && is_array($option['pros']) && [] !== $option['pros']) { ?>
        <div class="pros">
            <strong>Pros:</strong>
            <ul>
                <?php foreach ($option['pros'] as $pro) { ?>
                <li><?php echo $e($pro); ?></li>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>

        <?php if (isset($option['cons']) && is_array($option['cons']) && [] !== $option['cons']) { ?>
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
