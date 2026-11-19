<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$method      = (string) ($context['method'] ?? 'findAll');
$resultCount = (int) ($context['result_count'] ?? 0);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

$resultCountLabel = (string) $resultCount;

ob_start();
?>
<?php echo suggestionHeader('Paginate the result'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo t('%s %s in one go.', '' === $method ? t('The query') : '<code>' . $e($method) . '()</code>', $resultCount > 0 ? t('returned %s entities', $e($resultCountLabel)) : t('loads the whole table')); ?>
    <?php echo t('Every one of them is hydrated and kept in memory, and the number grows with the table.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">$entities = $repository-&gt;createQueryBuilder('e')
    -&gt;orderBy('e.id')
    -&gt;setFirstResult(($page - 1) * 50)
    -&gt;setMaxResults(50)
    -&gt;getQuery()
    -&gt;getResult();</code></pre></div>

<p><?php echo t('For batch jobs, iterate with <code>toIterable()</code> and call <code>$em-&gt;clear()</code> every few hundred entities.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/pagination.html', 'Doctrine pagination'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Paginate the %s results of %s', $resultCountLabel, $method),
];
