<?php

declare(strict_types=1);

/**
 * Template for OneToOneInverseSideAnalyzer suggestions.
 * Context variables:
 * @var string $entity_class - Short entity class name (inverse side)
 * @var string $entity_fqcn - Full entity class name
 * @var string $field_name - Field name on inverse side
 * @var string $target_class - Short target class name (owning side)
 * @var string $target_fqcn - Full target class name
 * @var string $mapped_by - mappedBy field name
 */

/** @var array<string, mixed> $context */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$fieldName = (string) ($context['field_name'] ?? 'field');
$targetClass = (string) ($context['target_class'] ?? 'Target');
$mappedBy = (string) ($context['mapped_by'] ?? 'field');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Avoid loading the OneToOne inverse side'); ?>

<div class="suggestion-content">
    <p><?php echo t('<code>%1$s::$%2$s</code> is the inverse side of a OneToOne; the foreign key lives in the <code>%3$s</code> table. Doctrine cannot proxy it, so each loaded <code>%1$s</code> runs one more SELECT, even if the field is never read.', $e($entityClass), $e($fieldName), $e($targetClass)); ?></p>

    <div class="query-item">
        <pre><code class="language-php">// Before: <?php echo $e($entityClass); ?> holds the inverse side
#[ORM\OneToOne(mappedBy: '<?php echo $e($mappedBy); ?>', targetEntity: <?php echo $e($targetClass); ?>::class)]
private ?<?php echo $e($targetClass); ?> $<?php echo $e($fieldName); ?> = null;

// After: <?php echo $e($entityClass); ?> owns the foreign key
#[ORM\OneToOne(targetEntity: <?php echo $e($targetClass); ?>::class, inversedBy: '<?php echo $e($mappedBy); ?>')]
#[ORM\JoinColumn(nullable: true)]
private ?<?php echo $e($targetClass); ?> $<?php echo $e($fieldName); ?> = null;</code></pre>
    </div>

    <p><?php echo t('If the mapping cannot move, drop the inverse field, or fetch-join it: <code>-&gt;leftJoin(\'e.%s\', \'t\')-&gt;addSelect(\'t\')</code>.', $e($fieldName)); ?></p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/faq.html', 'Doctrine FAQ: OneToOne Inverse Side'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Move the foreign key of the OneToOne %s::$%s to the side that is loaded most', $entityClass, $fieldName),
];
