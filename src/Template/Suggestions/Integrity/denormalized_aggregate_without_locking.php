<?php

declare(strict_types=1);

/**
 * Template for denormalized aggregate without locking suggestions.
 * Context variables:
 * @var string $entity_class - Short entity class name
 * @var string $entity_fqcn - Fully qualified entity class name
 * @var string $method_name - Method that mutates both aggregate and collection
 * @var array $mutated_fields - Numeric fields that are mutated
 * @var array $accessed_collections - Collection fields that are accessed
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$entityClass = $context['entity_class'] ?? 'Entity';
$methodName = $context['method_name'] ?? 'method';
$mutatedFields = $context['mutated_fields'] ?? [];
$accessedCollections = $context['accessed_collections'] ?? [];

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Add a version column to the aggregate'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s::%s()</code> updates <code>%s</code> from <code>%s</code> without any lock. Two concurrent requests can both read the old value, and the last flush overwrites the other.', $e($entityClass), $e($methodName), $e(implode(', ', array_map(fn (string $f): string => '$' . $f, $mutatedFields))), $e(implode(', ', array_map(fn (string $c): string => '$' . $c, $accessedCollections)))); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
class <?php echo $e($entityClass); ?>

{
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;
}
// A conflicting flush now throws OptimisticLockException: retry or report it.</code></pre></div>

<p><?php echo t('Or lock the row for the whole operation:'); ?></p>
<div class="query-item"><pre><code class="language-php">$em->wrapInTransaction(function () use ($em, $id): void {
    $entity = $em->find(<?php echo $e($entityClass); ?>::class, $id, LockMode::PESSIMISTIC_WRITE);
    $entity-><?php echo $e($methodName); ?>(/* ... */);
});</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/transactions-and-concurrency.html#locking-support', 'Doctrine Locking documentation'); ?>
</div>

<?php
$code = ob_get_clean();

$fieldList = implode(', ', array_map(fn (string $f): string => '$' . $f, $mutatedFields));

return [
    'code'        => $code,
    'description' => t(
        'Lock %s (#[ORM\Version] or a pessimistic lock) before updating %s',
        $entityClass,
        $fieldList,
    ),
];
