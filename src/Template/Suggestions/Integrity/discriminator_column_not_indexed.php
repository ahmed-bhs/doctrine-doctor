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
<p>Table <code><?php echo $e($tableName); ?></code> stores <?php echo $subtypeCount; ?> subtypes of <code><?php echo $e($rootClass); ?></code> without an index on <code><?php echo $e($columnName); ?></code>. Doctrine adds <code>WHERE <?php echo $e($columnName); ?> IN (...)</code> to every subclass query, so each one scans the table.</p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[ORM\Table(name: '<?php echo $e($tableName); ?>')]
#[ORM\Index(name: '<?php echo $e($indexName); ?>', columns: ['<?php echo $e($columnName); ?>'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: '<?php echo $e($columnName); ?>', type: 'string')]
class <?php echo $e($rootClass); ?>

{
}</code></pre></div>

<p>If queries usually filter on another column too, a composite index starting with <code><?php echo $e($columnName); ?></code> serves both.</p>
</div>

<?php
$html = (string) ob_get_clean();

return [
    'code' => $html,
    'description' => sprintf(
        'Add an index on the discriminator column %s of table %s',
        $columnName,
        $tableName,
    ),
];
