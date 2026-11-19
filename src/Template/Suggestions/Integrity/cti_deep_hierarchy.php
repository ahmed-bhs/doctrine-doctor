<?php

declare(strict_types=1);

/**
 * @var string $entity_class
 * @var string $root_class
 * @var int $depth
 * @var int $joins_required
 * @var list<string> $chain
 */

/** @var array<string, mixed> $context */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$rootClass = (string) ($context['root_class'] ?? 'RootEntity');
$depth = (int) ($context['depth'] ?? 0);
$joinsRequired = (int) ($context['joins_required'] ?? 0);
$chain = (array) ($context['chain'] ?? []);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Flatten the inheritance hierarchy'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s</code> sits %s levels deep in a JOINED hierarchy%s, so every query on it needs %s JOIN(s). If the subclasses share most fields, a single table removes these JOINs.', $e($entityClass), $depth, [] !== $chain ? ' (<code>' . $e(implode(' -> ', array_map(strval(...), $chain))) . '</code>)' : '', $joinsRequired); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
// Before: #[ORM\InheritanceType('JOINED')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
class <?php echo $e($rootClass); ?>

{
}</code></pre></div>

<p><?php echo t('Otherwise, merge intermediate classes that add few fields, or replace a level with an embeddable or an association.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/inheritance-mapping.html', 'Doctrine: Inheritance Mapping'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t(
        'Flatten the %s hierarchy: %d levels deep, %d JOIN(s) per query',
        $entityClass,
        $depth,
        $joinsRequired,
    ),
];
