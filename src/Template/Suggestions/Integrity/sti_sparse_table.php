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
<?php echo suggestionHeader('Consider JOINED inheritance'); ?>

<div class="suggestion-content">
    <p><code><?php echo $e($subclass); ?></code> uses <?php echo $ownFieldsCount; ?> of the <?php echo $totalColumns; ?> columns of the single table shared by <?php echo $subclassCount; ?> subclasses (<?php echo $sparsePercentage; ?>% unused). Its rows carry NULL in every column of the other subclasses.</p>

    <div class="query-item">
        <pre><code class="language-php">#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
class <?php echo $e($rootClass); ?>

{
    // shared fields stay in the root table
}

#[ORM\Entity]
class <?php echo $e($subclass); ?> extends <?php echo $e($rootClass); ?>

{
    // its own fields move to its own table
}</code></pre>
    </div>

    <p>JOINED adds a join per query on the hierarchy; keep single table if the subclasses are mostly read together.</p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/inheritance-mapping.html#class-table-inheritance', 'Doctrine: Class Table Inheritance'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Consider JOINED inheritance for %s: %s uses %d of %d columns', $rootClass, $subclass, $ownFieldsCount, $totalColumns),
];
