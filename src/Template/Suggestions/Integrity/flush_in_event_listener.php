<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$entityClass = $context['entity_class'] ?? 'Entity';
$entityFqcn = $context['entity_fqcn'] ?? 'App\\Entity\\Entity';
$methodName = $context['method_name'] ?? 'method';
$event = $context['event'] ?? 'prePersist';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

$eventAttribute = match ($event) {
    'prePersist' => 'PrePersist',
    'postPersist' => 'PostPersist',
    'preUpdate' => 'PreUpdate',
    'postUpdate' => 'PostUpdate',
    'preRemove' => 'PreRemove',
    'postRemove' => 'PostRemove',
    'postLoad' => 'PostLoad',
    'preFlush' => 'PreFlush',
    'onFlush' => 'OnFlush',
    default => ucfirst($event),
};

ob_start();
?>

<div class="suggestion-header">
    <h4>Remove flush() from lifecycle callback</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Infinite loop risk:</strong> <code><?php echo $e($entityClass); ?>::<?php echo $e($methodName); ?>()</code>
        calls <code>flush()</code> inside a <code>#[<?php echo $e($eventAttribute); ?>]</code> lifecycle callback.
        This re-triggers the UnitOfWork computation, which can invoke the same callback again, causing an infinite loop.
    </div>

    <h4>Problem</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class <?php echo $e($entityClass); ?>

{
    #[ORM\<?php echo $e($eventAttribute); ?>]
    public function <?php echo $e($methodName); ?>(): void
    {
        // ...
        $this->entityManager->flush(); // DANGEROUS: triggers UnitOfWork again
    }
}</code></pre>
    </div>

    <h4>Solution: Use an event listener service</h4>
    <div class="query-item">
        <pre><code class="language-php">// Remove #[HasLifecycleCallbacks] and move logic to a listener
class <?php echo $e($entityClass); ?>Listener
{
    #[ORM\<?php echo $e($eventAttribute); ?>]
    public function <?php echo $e($event); ?>(<?php echo $e($entityClass); ?> $entity, LifecycleEventArgs $event): void
    {
        // Perform your logic here WITHOUT calling flush()
        // Doctrine will flush automatically at the end of the request
    }
}</code></pre>
    </div>

    <p>
        <strong>Note:</strong> Doctrine explicitly warns against calling <code>flush()</code> inside lifecycle events.
        If you need to persist additional changes, schedule them via <code>UnitOfWork::scheduleExtraUpdate()</code>
        or use the <code>onFlush</code> event with <code>UnitOfWork::computeChangeSet()</code>.
    </p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/events.html" target="_blank" class="doc-link">
            Doctrine Events documentation
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Remove flush() call from %s::%s() lifecycle callback (%s) to prevent infinite loops',
        $entityClass,
        $methodName,
        $event,
    ),
];
