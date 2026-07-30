<?php

declare(strict_types=1);

/**
 * Suggestion template for adding #[ORM\Version] field for optimistic locking.
 * Context variables: entity_class
 */

$entity_class = (string) ($context['entity_class'] ?? '');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<p>This entity is involved in concurrent write patterns but lacks an <code>#[ORM\Version]</code> field for optimistic locking. Without version control, concurrent processes may overwrite each other's changes.</p>

<h4>What is Optimistic Locking?</h4>

<p>Optimistic locking prevents lost updates by storing a version number on each entity. When you update an entity, Doctrine checks that the version hasn't changed since it was loaded. If it has, a <code>OptimisticLockException</code> is thrown.</p>

<h4>How to Add It</h4>

<div class="code-block">
<pre><code class="language-php">#[ORM\Entity]
class <?php echo $e($entity_class); ?> {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 0;

    // ... other properties
}</code></pre>
</div>

<h4>How it works</h4>

<p>The <code>#[ORM\Version]</code> field is automatically incremented on every flush (UPDATE) and included in all UPDATE queries with <code>WHERE version = ?</code>. Throws <code>OptimisticLockException</code> if the WHERE fails due to a version mismatch, requiring you to reload the entity and retry the operation.</p>

<h4>Handling OptimisticLockException</h4>

<div class="code-block">
<pre><code class="language-php">use Doctrine\ORM\Exception\OptimisticLockException;

try {
    $this->entityManager->flush();
} catch (OptimisticLockException $e) {
    // Reload the entity and retry
    $this->entityManager->refresh($entity);
    // Reapply your changes
    $this->entityManager->flush();
}</code></pre>
</div>

<h4>Pessimistic Locking Alternative</h4>

<p>For high-contention scenarios, consider pessimistic locks (SELECT ... FOR UPDATE) instead:</p>

<div class="code-block">
<pre><code class="language-php">$entity = $this->entityManager->find(
    <?php echo $e($entity_class); ?>::class,
    $id,
    LockMode::PESSIMISTIC_WRITE
);
// No other process can read/write this row until your transaction ends
</code></pre>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add #[ORM\Version] field for optimistic locking on concurrent writes',
];
