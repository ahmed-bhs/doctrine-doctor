<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $badCode
 * @var mixed $goodCode
 * @var array<string, mixed> $context
 */
$badCode  = (string) ($context['bad_code'] ?? '');
$goodCode = (string) ($context['good_code'] ?? '');
// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Use IS NULL instead of = NULL'); ?>
<div class="suggestion-content">
<p><?php echo t('In SQL, <code>= NULL</code> and <code>!= NULL</code> evaluate to UNKNOWN, never TRUE, so the condition matches no row. Use <code>IS NULL</code> / <code>IS NOT NULL</code> or the QueryBuilder <code>isNull()</code> helpers.'); ?></p>

<div class="query-item"><pre><code class="language-php">// Before
<?php echo $e($badCode); ?>


// After
<?php echo $e($goodCode); ?></code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/query-builder.html#helper-methods', 'Doctrine QueryBuilder Helper Methods'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Use IS NULL instead of = NULL for correct SQL NULL comparisons'),
];
