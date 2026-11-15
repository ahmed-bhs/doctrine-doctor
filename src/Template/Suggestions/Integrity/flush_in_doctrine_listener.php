<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$listenerClass = $context['listener_class'] ?? 'Listener';
$methodName = $context['method_name'] ?? 'postFlush';
$event = $context['event'] ?? 'postFlush';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Move flush() out of the Doctrine listener'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($listenerClass); ?>::<?php echo $e($methodName); ?>()</code> runs while Doctrine is flushing. Let the listener record what changed, and do the follow-up work in the service that called <code>flush()</code>, after it returns.</p>

<div class="query-item"><pre><code class="language-php">// Before, in the <?php echo $e($event); ?> listener
$this->sync->run($entity);
$this->entityManager->flush();

// After, in the service that saves the entity
$this->entityManager->flush();
$this->sync->run($entity);
$this->entityManager->flush();</code></pre></div>

<p>To change other entities within the same flush, use an <code>onFlush</code> listener and call <code>$uow->computeChangeSet()</code> for each of them.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/events.html', 'Doctrine Events documentation'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Move flush() out of %s::%s() (%s)', $listenerClass, $methodName, $event),
];
