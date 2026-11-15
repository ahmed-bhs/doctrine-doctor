<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$code = (string) ($context['code'] ?? '');
$optimization = (string) ($context['optimization'] ?? '');
$executionTime = (float) ($context['execution_time'] ?? 0.0);
$threshold = (float) ($context['threshold'] ?? 0.0);

// PHP code is shown as PHP, anything else as SQL
$isPHP = str_contains($code, '$') || str_contains($code, '->') || str_contains($code, '::');
ob_start();
?>
<?php echo suggestionHeader('Speed up this slow query'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    The query took <?php echo number_format($executionTime, 2); ?>ms, above the <?php echo $threshold; ?>ms threshold.
    Run EXPLAIN on it to find the missing index or the scan.
</div>
<div class="query-item"><pre><code class="language-<?php echo $isPHP ? 'php' : 'sql'; ?>"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></code></pre></div>
<?php if ('' !== $optimization) { ?>
<p><?php echo nl2br(htmlspecialchars($optimization, ENT_QUOTES, 'UTF-8')); ?></p>
<?php } ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/improving-performance.html', 'Doctrine ORM Improving Performance'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Optimize slow query (%.2fms)', $executionTime)];
