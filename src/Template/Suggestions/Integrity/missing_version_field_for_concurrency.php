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
<?php echo suggestionHeader('Add a version field for optimistic locking'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s</code> is written concurrently without a <code>#[ORM\Version]</code> field, so the last flush silently overwrites earlier changes. With a version, Doctrine adds <code>WHERE version = ?</code> to each UPDATE and throws <code>OptimisticLockException</code> on a conflict.', $e($entity_class)); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Version]
#[ORM\Column(type: Types::INTEGER)]
private int $version = 1;

// Caller
try {
    $em-&gt;flush();
} catch (\Doctrine\ORM\OptimisticLockException) {
    // reload the entity, reapply the change, retry
}</code></pre></div>

<p><?php echo t('Under high contention, lock the row on read instead with <code>$em-&gt;find(%s::class, $id, LockMode::PESSIMISTIC_WRITE)</code> inside a transaction.', $e($entity_class)); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/transactions-and-concurrency.html#optimistic-locking', 'Doctrine ORM Optimistic Locking'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Add an #[ORM\Version] field for optimistic locking'),
];
