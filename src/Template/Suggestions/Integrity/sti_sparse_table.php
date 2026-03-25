<?php

declare(strict_types=1);

/**
 * @var string $root_class
 * @var string $root_fqcn
 * @var string $subclass
 * @var string $subclass_fqcn
 * @var int $own_fields_count
 * @var int $total_columns
 * @var int $sparse_percentage
 * @var int $subclass_count
 */

/** @var array<string, mixed> $context */
$rootClass = (string) ($context['root_class'] ?? 'RootEntity');
$subclass = (string) ($context['subclass'] ?? 'SubEntity');
$ownFieldsCount = (int) ($context['own_fields_count'] ?? 0);
$totalColumns = (int) ($context['total_columns'] ?? 0);
$sparsePercentage = (int) ($context['sparse_percentage'] ?? 0);
$subclassCount = (int) ($context['subclass_count'] ?? 0);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Sparse STI Table: <?php echo $e($rootClass); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Subclass <strong><?php echo $e($subclass); ?></strong> uses only <strong><?php echo $ownFieldsCount; ?></strong> of <strong><?php echo $totalColumns; ?></strong> columns (<?php echo $sparsePercentage; ?>% unused).
        The remaining columns are always NULL for rows of this type, wasting storage and reducing cache efficiency.
    </div>

    <h4>Why this matters</h4>
    <p>Single Table Inheritance stores all <?php echo $subclassCount; ?> subclass(es) in one table.
    When subtypes have very different fields, the table becomes wide and sparse.
    Each row carries NULL values for columns belonging to other subtypes.</p>

    <h4>Consider: Switch to Class Table Inheritance (JOINED)</h4>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
class <?php echo $e($rootClass); ?>

{
    // Shared fields stay in the parent table
}

class <?php echo $e($subclass); ?> extends <?php echo $e($rootClass); ?>

{
    // Specific fields get their own table
    // No more NULL columns for other subtypes
}</code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/inheritance-mapping.html#class-table-inheritance" target="_blank" class="doc-link">
            Doctrine: Class Table Inheritance
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'STI hierarchy %s is sparse: subclass %s uses only %d of %d columns (%d%% unused) — consider switching to JOINED inheritance',
        $rootClass,
        $subclass,
        $ownFieldsCount,
        $totalColumns,
        $sparsePercentage,
    ),
];
