<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$rootClass = (string) ($context['root_class'] ?? 'RootEntity');
$columnName = (string) ($context['column_name'] ?? 'dtype');
$tableName = (string) ($context['table_name'] ?? 'entity_table');
$subtypeCount = (int) ($context['subtype_count'] ?? 0);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$indexName = 'idx_' . $tableName . '_' . $columnName;

ob_start();
?>
<?php echo suggestionHeader('Index the discriminator column'); ?>
<div class="suggestion-content">
<p><?php echo t('Table <code>%s</code> stores %s subtypes of <code>%s</code> without an index on <code>%s</code>. Doctrine adds <code>WHERE %s IN (...)</code> to every subclass query, so each one scans the table.', $e($tableName), $subtypeCount, $e($rootClass), $e($columnName), $e($columnName)); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[ORM\Table(name: '<?php echo $e($tableName); ?>')]
#[ORM\Index(name: '<?php echo $e($indexName); ?>', columns: ['<?php echo $e($columnName); ?>'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: '<?php echo $e($columnName); ?>', type: 'string')]
class <?php echo $e($rootClass); ?>

{
}</code></pre></div>

<p><?php echo t('If queries usually filter on another column too, a composite index starting with <code>%s</code> serves both.', $e($columnName)); ?></p>
</div>

<?php
$html = (string) ob_get_clean();

return [
    'code' => $html,
    'description' => t(
        'Add an index on the discriminator column %s of table %s',
        $columnName,
        $tableName,
    ),
];
