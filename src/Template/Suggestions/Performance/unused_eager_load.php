<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$unusedTables = $context['unused_tables'] ?? ['related_table'];
$unusedAliases = $context['unused_aliases'] ?? ['r'];
$count = max(0, (int) ($context['count'] ?? 0));
if (!is_array($unusedTables) || [] === $unusedTables) {
    $unusedTables = ['related_table'];
}
if (!is_array($unusedAliases) || [] === $unusedAliases) {
    $unusedAliases = ['r'];
}
$unusedTables = array_values(array_map(static fn (mixed $value): string => (string) $value, $unusedTables));
$unusedAliases = array_values(array_map(static fn (mixed $value): string => (string) $value, $unusedAliases));
$count = max($count, count($unusedTables), count($unusedAliases));

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Remove the unused fetch joins'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo $count; ?> fetch-joined <?php echo 1 === $count ? 'table is' : 'tables are'; ?> hydrated but never read:
    <code><?php echo implode('</code>, <code>', array_map($e(...), $unusedTables)); ?></code>.
    Each one adds columns to every row and objects to the unit of work.
</div>

<div class="query-item"><pre><code class="language-php">// Before
$qb-&gt;select('a'<?php foreach ($unusedAliases as $alias) { ?>, '<?php echo $e($alias); ?>'<?php } ?>)
<?php foreach ($unusedAliases as $alias) { ?>
   -&gt;leftJoin('a.relation', '<?php echo $e($alias); ?>')
<?php } ?>   -&gt;getQuery();

// After: select and join only what the code reads
$qb-&gt;select('a')-&gt;getQuery();</code></pre></div>

<p>If a JOIN also filters rows (INNER JOIN or a WHERE on its alias), keep the JOIN and only drop the alias from the SELECT.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins', 'Doctrine DQL JOIN Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Remove %d unused fetch join(s) from the query', $count),
];
