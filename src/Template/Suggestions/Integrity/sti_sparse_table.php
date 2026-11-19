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
    <p><?php echo t('<code>%s</code> uses %s of the %s columns of the single table shared by %s subclasses (%s unused). Its rows carry NULL in every column of the other subclasses.', $e($subclass), $ownFieldsCount, $totalColumns, $subclassCount, $sparsePercentage . '%'); ?></p>

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

    <p><?php echo t('JOINED adds a join per query on the hierarchy; keep single table if the subclasses are mostly read together.'); ?></p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/inheritance-mapping.html#class-table-inheritance', 'Doctrine: Class Table Inheritance'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Consider JOINED inheritance for %s: %s uses %d of %d columns', $rootClass, $subclass, $ownFieldsCount, $totalColumns),
];
