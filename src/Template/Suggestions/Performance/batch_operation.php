<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$table          = is_string($context['table'] ?? null) ? $context['table'] : 'related_table';
$operationCount = max(0, (int) ($context['operation_count'] ?? 0));
$isDelete       = 'DELETE' === ($context['operation_type'] ?? 'UPDATE');
$e              = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<?php echo suggestionHeader('Replace per-entity writes with one statement'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo t('%d separate %s statements ran on <code>%s</code>, one per entity.', $operationCount, $isDelete ? 'DELETE' : 'UPDATE', $e($table)); ?>
    <?php echo t('One set-based statement does the same work in one round trip, but skips lifecycle callbacks, listeners and cascades.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">use Doctrine\DBAL\ArrayParameterType;
<?php if ($isDelete) { ?>

$em-&gt;getConnection()-&gt;executeStatement(
    'DELETE FROM <?php echo $e($table); ?> WHERE id IN (?)',
    [$ids],
    [ArrayParameterType::INTEGER],
);<?php } else { ?>
use Doctrine\DBAL\ParameterType;

$em-&gt;getConnection()-&gt;executeStatement(
    'UPDATE <?php echo $e($table); ?> SET status = ? WHERE id IN (?)',
    ['archived', $ids],
    [ParameterType::STRING, ArrayParameterType::INTEGER],
);<?php } ?></code></pre></div>

<p><?php echo t('To keep the listeners, stay in the ORM and flush and clear in batches; it still sends one statement per entity.'); ?></p>
<div class="query-item"><pre><code class="language-php">foreach ($entities as $i =&gt; $entity) {
    $entity-&gt;archive();
    if (0 === ($i + 1) % 50) {
        $em-&gt;flush();
        $em-&gt;clear();
    }
}
$em-&gt;flush();</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/batch-processing.html', 'Doctrine ORM Batch Processing'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => t('Replace %d per-entity writes with one set-based statement, or batch them with flush() and clear()', $operationCount)];
