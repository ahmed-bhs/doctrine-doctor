<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $badCode
 * @var mixed $goodCode
 * @var mixed $context
 */
['bad_code' => $badCode, 'good_code' => $goodCode] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Use IS NULL instead of = NULL</h4>
</div>

<div class="suggestion-content">
    <p>SQL NULL comparisons require IS NULL or IS NOT NULL operators. Direct equality comparisons with NULL always return NULL (unknown), not true or false.</p>

    <h4>Current Code (Incorrect)</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($badCode); ?></code></pre>
    </div>

    <div class="alert alert-warning">
        Using <code>= NULL</code> or <code>!= NULL</code> will NOT work. The condition never matches rows because <code>= NULL</code> returns UNKNOWN, not TRUE.
    </div>

    <h4>Correct Code</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($goodCode); ?></code></pre>
    </div>

    <p><code>IS NULL</code> and <code>IS NOT NULL</code> are the SQL standard. <code>= NULL</code> always returns UNKNOWN (three-valued logic), never TRUE or FALSE.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/query-builder.html#helper-methods" target="_blank" class="doc-link">
            📖 Doctrine QueryBuilder Helper Methods →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use IS NULL instead of = NULL for correct SQL NULL comparisons',
];
