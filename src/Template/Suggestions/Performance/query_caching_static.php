<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$sql = $context['sql'] ?? 'SELECT * FROM static_table';
$table_name = $context['table_name'] ?? 'static_table';

$sql = html_entity_decode((string) $sql, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Cache the result of this lookup query'); ?>
<div class="suggestion-content">
<div class="alert alert-info">
    <?php echo t('<code>%s</code> holds rarely changing lookup data, yet the query reaches the database on every request.', $e($table_name)); ?>
    <?php echo t('A result cache serves it until the data changes.'); ?>
</div>

<div class="query-item"><pre><code class="language-php">$query-&gt;enableResultCache(86400, '<?php echo $e($table_name); ?>_all');

// After writing to <?php echo $e($table_name); ?>, drop the cached entry
$em-&gt;getConfiguration()-&gt;getResultCache()?-&gt;deleteItem('<?php echo $e($table_name); ?>_all');</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/caching.html', 'Doctrine ORM Caching'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Cache queries on the lookup table %s', $table_name),
];
