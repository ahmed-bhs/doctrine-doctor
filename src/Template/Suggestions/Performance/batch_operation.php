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
<?php echo $operationCount; ?> individual <?php echo $isDelete ? 'DELETE' : 'UPDATE'; ?> statements on <code><?php echo $e($table); ?></code>,
one per entity. A single set-based statement does the same work in one round trip.</div>

<h4>Solution: one statement for the whole set</h4>
<div class="query-item"><pre><code class="language-php"><?php if ($isDelete) { ?>// DQL, on the entity model
$em-&gt;createQuery('DELETE FROM App\Entity\Entity e WHERE e.id IN (:ids)')
   -&gt;setParameter('ids', $ids)
   -&gt;execute();

// or DBAL, in SQL
$em-&gt;getConnection()-&gt;executeStatement(
    'DELETE FROM <?php echo $e($table); ?> WHERE id IN (?)',
    [$ids],
    [\Doctrine\DBAL\ArrayParameterType::INTEGER],
);<?php } else { ?>// DQL, on the entity model
$em-&gt;createQuery('UPDATE App\Entity\Entity e SET e.status = :status WHERE e.id IN (:ids)')
   -&gt;setParameter('status', 'archived')
   -&gt;setParameter('ids', $ids)
   -&gt;execute();

// or DBAL, in SQL
$em-&gt;getConnection()-&gt;executeStatement(
    'UPDATE <?php echo $e($table); ?> SET status = ? WHERE id IN (?)',
    ['archived', $ids],
    [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER],
);<?php } ?></code></pre></div>
<p>Both bypass the unit of work: no lifecycle callbacks or listeners, no cascades, and entities already loaded
keep their old values until refreshed. Use them when the loop only changes columns.</p>

<h4>Keeping the ORM: flush and clear in batches</h4>
<div class="query-item"><pre><code class="language-php">$batchSize = 50;
foreach ($entities as $i =&gt; $entity) {
    $entity-&gt;process();

    if (0 === ($i + 1) % $batchSize) {
        $em-&gt;flush();
        $em-&gt;clear(); // frees the managed entities
    }
}
$em-&gt;flush();</code></pre></div>
<p>This keeps callbacks and listeners but still sends one statement per entity.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/batch-processing.html', 'Doctrine ORM Batch Processing'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Replace %d per-entity writes with one set-based statement, or batch them with flush() and clear()', $operationCount)];
