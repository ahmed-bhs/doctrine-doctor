<?php

declare(strict_types=1);

/**
 * @var string $root_class
 * @var string $subclass
 * @var list<string> $own_fields
 * @var int $own_field_count
 * @var int $parent_field_count
 */

/** @var array<string, mixed> $context */
$rootClass = (string) ($context['root_class'] ?? 'RootEntity');
$subclass = (string) ($context['subclass'] ?? 'SubEntity');
$ownFields = (array) ($context['own_fields'] ?? []);
$ownFieldCount = (int) ($context['own_field_count'] ?? 0);
$parentFieldCount = (int) ($context['parent_field_count'] ?? 0);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Store the subclass in the parent table'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($subclass); ?></code> adds <?php echo $ownFieldCount; ?> field(s)<?php if ([] !== $ownFields) { ?> (<code><?php echo $e(implode(', ', array_map(strval(...), $ownFields))); ?></code>)<?php } ?> to <code><?php echo $e($rootClass); ?></code>, which has <?php echo $parentFieldCount; ?>. Each polymorphic query still pays a JOIN for its table; single table inheritance stores these as nullable columns instead.</p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
// Before: #[ORM\InheritanceType('JOINED')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
class <?php echo $e($rootClass); ?>

{
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/inheritance-mapping.html#single-table-inheritance', 'Doctrine: Single Table Inheritance'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Move CTI subclass %s (%d field(s)) to SINGLE_TABLE to drop a JOIN for %d column(s)',
        $subclass,
        $ownFieldCount,
        $ownFieldCount,
    ),
];
