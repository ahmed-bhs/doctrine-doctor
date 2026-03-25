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

<div class="suggestion-header">
    <h4>Add locking mechanism to aggregate root</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Race condition risk:</strong> <code><?php echo $e($entityClass); ?>::<?php echo $e($methodName); ?>()</code> modifies
        aggregate field(s) <code><?php echo $e(implode(', ', array_map(fn (string $f): string => '$' . $f, $mutatedFields))); ?></code>
        alongside collection(s) <code><?php echo $e(implode(', ', array_map(fn (string $c): string => '$' . $c, $accessedCollections))); ?></code>
        without a locking mechanism. Doctrine explicitly warns that denormalized aggregate roots require locking.
    </div>

    <h4>Option 1: Optimistic locking with <code>#[ORM\Version]</code> (recommended)</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Entity]
class <?php echo $e($entityClass); ?>

{
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 0;

<?php foreach ($mutatedFields as $field): ?>
    #[ORM\Column(type: 'integer')]
    private int $<?php echo $e($field); ?> = 0;

<?php endforeach; ?>
<?php foreach ($accessedCollections as $collection): ?>
    #[ORM\OneToMany(...)]
    private Collection $<?php echo $e($collection); ?>;

<?php endforeach; ?>
}</code></pre>
    </div>

    <h4>Option 2: Pessimistic locking at the call site</h4>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\DBAL\LockMode;

$em->wrapInTransaction(function () use ($em, $id) {
    $<?php echo $e(lcfirst($entityClass)); ?> = $em->find(
        <?php echo $e($entityClass); ?>::class,
        $id,
        LockMode::PESSIMISTIC_WRITE,
    );
    $<?php echo $e(lcfirst($entityClass)); ?>-><?php echo $e($methodName); ?>(...);
});</code></pre>
    </div>

    <p>
        <strong>Note:</strong> Optimistic locking throws <code>OptimisticLockException</code> on conflict — the application
        must handle retries. Pessimistic locking requires explicit transaction management and may reduce throughput.
    </p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/transactions-and-concurrency.html#locking-support" target="_blank" class="doc-link">
            Doctrine Locking documentation
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

$fieldList = implode(', ', array_map(fn (string $f): string => '$' . $f, $mutatedFields));

return [
    'code'        => $code,
    'description' => sprintf(
        'Add a locking mechanism (#[ORM\Version] or pessimistic lock) to %s to prevent race conditions on aggregate field(s) %s',
        $entityClass,
        $fieldList,
    ),
];
