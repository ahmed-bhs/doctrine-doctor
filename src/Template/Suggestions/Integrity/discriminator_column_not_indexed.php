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

<div class="suggestion-header">
    <h4>Unindexed Discriminator Column: <?php echo $e($rootClass); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Table <code><?php echo $e($tableName); ?></code> stores <strong><?php echo $subtypeCount; ?></strong> subtypes
        but has no index on <code><?php echo $e($columnName); ?></code>.
        Doctrine appends a <code>WHERE <?php echo $e($columnName); ?> IN (...)</code> filter to every subclass query,
        so each one scans the full table.
    </div>

    <h4>Fix: declare the index on the root entity</h4>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: '<?php echo $e($tableName); ?>')]
#[ORM\Index(name: '<?php echo $e($indexName); ?>', columns: ['<?php echo $e($columnName); ?>'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: '<?php echo $e($columnName); ?>', type: 'string')]
class <?php echo $e($rootClass); ?>

{
}</code></pre>
    </div>

    <h4>Equivalent SQL</h4>
    <div class="query-item">
        <pre><code class="language-sql">CREATE INDEX <?php echo $e($indexName); ?> ON <?php echo $e($tableName); ?> (<?php echo $e($columnName); ?>);</code></pre>
    </div>

    <p>The gain grows with the row count and with how unevenly the subtypes are distributed.
    On a table where one subtype dominates, an index on the rare subtypes pays off most.
    If queries usually combine the discriminator with another column, a composite index starting
    with <code><?php echo $e($columnName); ?></code> serves both.</p>
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
