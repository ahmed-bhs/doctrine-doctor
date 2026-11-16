<?php

declare(strict_types=1);

/**
 * Suggestion template for refactoring ManyToMany with extra columns.
 * Context variables: entity_class, field_name, join_table, extra_columns
 */
$entity_class = (string) ($context['entity_class'] ?? '');
$field_name = (string) ($context['field_name'] ?? '');
$join_table = (string) ($context['join_table'] ?? '');
$extra_columns = (array) ($context['extra_columns'] ?? []);
$target_entity = (string) ($context['target_entity'] ?? '');
$shortTarget = '' === $target_entity ? 'Target' : substr($target_entity, (int) strrpos('\\' . $target_entity, '\\'));
$linkClass = ucfirst((string) preg_replace('/s$/', '', $field_name)) . 'Entry';
$camel = static fn (string $column): string => lcfirst(str_replace('_', '', ucwords($column, '_')));
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php $shortEntity = (false !== strrpos($entity_class, '\\')) ? substr($entity_class, strrpos($entity_class, '\\') + 1) : $entity_class; ?>
<?php echo suggestionHeader('Map the join table as an entity'); ?>
<div class="suggestion-content">
<p>Join table <code><?php echo $e($join_table); ?></code> holds columns beyond the two foreign keys (<code><?php echo implode('</code>, <code>', array_map(static fn (mixed $col): string => $e((string) $col), $extra_columns)); ?></code>). A <code>ManyToMany</code> mapping cannot read or write them, so map the table as its own entity with two <code>ManyToOne</code> associations.</p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[ORM\Table(name: '<?php echo $e($join_table); ?>')]
class <?php echo $e($linkClass); ?>

{
    #[ORM\Id, ORM\ManyToOne(inversedBy: '<?php echo $e($field_name); ?>')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private <?php echo $e($shortEntity); ?> $owner;

    #[ORM\Id, ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private <?php echo $e($shortTarget); ?> $<?php echo $e(lcfirst($shortTarget)); ?>;
<?php foreach ($extra_columns as $column) {
    $column = (string) $column;
    $isDate = 1 === preg_match('/_(at|on|date)$/', $column); ?>

    #[ORM\Column(name: '<?php echo $e($column); ?>'<?php echo $isDate ? ', type: Types::DATETIME_IMMUTABLE' : ''; ?>)]
    private <?php echo $isDate ? '\\DateTimeImmutable' : 'string'; ?> $<?php echo $e($camel($column)); ?>;
<?php } ?>
}</code></pre></div>

<p>On <code><?php echo $e($shortEntity); ?></code>, replace <code>$<?php echo $e($field_name); ?></code> with <code>#[ORM\OneToMany(targetEntity: <?php echo $e($linkClass); ?>::class, mappedBy: 'owner')]</code>.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/composite-primary-keys.html#use-case-3-join-table-with-metadata', 'Doctrine ORM: join table with metadata'); ?>
</div>
<?php
    $code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Map the join table with extra columns as an entity with two ManyToOne associations',
];
