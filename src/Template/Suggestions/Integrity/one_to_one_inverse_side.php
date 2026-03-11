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

<div class="suggestion-header">
    <h4>OneToOne Inverse Side: <?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></strong> is the <strong>inverse side</strong> (mappedBy) of a bidirectional OneToOne with <strong><?php echo $e($targetClass); ?></strong>.
        Doctrine <strong>cannot lazy-load</strong> this side because the foreign key lives in <code><?php echo $e($targetClass); ?></code>'s table.
        Every load of <code><?php echo $e($entityClass); ?></code> triggers an extra SELECT, even if <code>$<?php echo $e($fieldName); ?></code> is never accessed.
    </div>

    <h4>Why this happens</h4>
    <p>Doctrine needs to know whether the related <code><?php echo $e($targetClass); ?></code> exists (or is <code>null</code>) and what its ID is.
    Since the FK is on <code><?php echo $e($targetClass); ?></code>'s table, it cannot determine this without querying. A proxy cannot be created because Doctrine doesn't even know <em>if</em> the target row exists.</p>

    <h4>Option 1: Move the owning side to <?php echo $e($entityClass); ?></h4>
    <p>If you load <code><?php echo $e($entityClass); ?></code> more often than <code><?php echo $e($targetClass); ?></code>, flip the owning side:</p>
    <div class="query-item">
        <pre><code class="language-php">// <?php echo $e($entityClass); ?> becomes the owning side (has the FK)
class <?php echo $e($entityClass); ?>

{
    #[ORM\OneToOne(targetEntity: <?php echo $e($targetClass); ?>::class, inversedBy: '<?php echo $e($mappedBy); ?>')]
    #[ORM\JoinColumn(nullable: false)]
    private <?php echo $e($targetClass); ?> $<?php echo $e($fieldName); ?>;
}

// <?php echo $e($targetClass); ?> becomes the inverse side
class <?php echo $e($targetClass); ?>

{
    #[ORM\OneToOne(mappedBy: '<?php echo $e($fieldName); ?>', targetEntity: <?php echo $e($entityClass); ?>::class)]
    private <?php echo $e($entityClass); ?> $<?php echo $e($mappedBy); ?>;
}

// Then generate a migration to move the FK column</code></pre>
    </div>

    <h4>Option 2: Make the relation unidirectional</h4>
    <p>If <code><?php echo $e($entityClass); ?></code> doesn't really need to access <code>$<?php echo $e($fieldName); ?></code>, remove the inverse side entirely:</p>
    <div class="query-item">
        <pre><code class="language-php">// Keep only the owning side on <?php echo $e($targetClass); ?>

class <?php echo $e($targetClass); ?>

{
    #[ORM\OneToOne(targetEntity: <?php echo $e($entityClass); ?>::class)]
    #[ORM\JoinColumn(nullable: false)]
    private <?php echo $e($entityClass); ?> $<?php echo $e($mappedBy); ?>;
}

// Remove $<?php echo $e($fieldName); ?> from <?php echo $e($entityClass); ?> entirely</code></pre>
    </div>

    <h4>Option 3: Use a fetch join when loading <?php echo $e($entityClass); ?></h4>
    <p>If you cannot change the mapping, always use a fetch join to avoid N+1:</p>
    <div class="query-item">
        <pre><code class="language-php">$qb = $repository->createQueryBuilder('e')
    ->leftJoin('e.<?php echo $e($fieldName); ?>', 't')
    ->addSelect('t')
    ->getQuery()
    ->getResult();</code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/faq.html" target="_blank" class="doc-link">
            📜 Doctrine FAQ: OneToOne Inverse Side
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'OneToOne inverse side %s::$%s forces extra queries on every load — consider flipping owning side or making it unidirectional',
        $entityClass,
        $fieldName,
    ),
];
