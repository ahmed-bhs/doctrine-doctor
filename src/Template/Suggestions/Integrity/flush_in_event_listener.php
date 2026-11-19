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
    default => ucfirst((string) $event),
};

ob_start();
?>
<?php echo suggestionHeader('Remove flush() from the lifecycle callback'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s::%s()</code> calls <code>flush()</code> inside a <code>#[%s]</code> callback. The nested flush recomputes the unit of work and can trigger the same callback again, in a loop.', $e($entityClass), $e($methodName), $e($eventAttribute)); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class <?php echo $e($entityClass); ?>

{
    #[ORM\<?php echo $e($eventAttribute); ?>]
    public function <?php echo $e($methodName); ?>(): void
    {
        // Change this entity's fields only: the running flush saves them.
        // Before: $this->entityManager->flush();
    }
}</code></pre></div>

<p><?php echo t('To persist other entities, do it in an <code>onFlush</code> listener and call <code>$uow->computeChangeSet()</code> for each of them.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/events.html', 'Doctrine Events documentation'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t(
        'Remove flush() from the %s::%s() lifecycle callback (%s)',
        $entityClass,
        $methodName,
        $event,
    ),
];
