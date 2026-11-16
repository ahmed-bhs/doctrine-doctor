<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$entityClass = $context['entity_class'] ?? 'Customer';
$field = $context['field'] ?? 'orders';
$targetClass = $context['target_class'] ?? 'Order';
$mappedBy = $context['mapped_by'] ?? 'customer';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$owner = lcfirst((string) $entityClass);

ob_start();
?>
<?php echo suggestionHeader('Keep only the owning side'); ?>
<div class="suggestion-content">
<p>Remove <code><?php echo $e($entityClass); ?>::$<?php echo $e($field); ?></code> with its getter, adder and remover, and keep <code><?php echo $e($targetClass); ?>::$<?php echo $e($mappedBy); ?></code>. When you need the list, ask the repository, and a DQL join still works without the inverse side.</p>

<div class="query-item"><pre><code class="language-php">// Load the list when you need it
$<?php echo $e($field); ?> = $<?php echo $e(lcfirst((string) $targetClass)); ?>Repository->findBy(['<?php echo $e($mappedBy); ?>' => $<?php echo $e($owner); ?>]);

// Join from <?php echo $e($entityClass); ?> without the inverse side
SELECT c, COUNT(x) FROM <?php echo $e($entityClass); ?> c
JOIN <?php echo $e($targetClass); ?> x WITH x.<?php echo $e($mappedBy); ?> = c
GROUP BY c</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/unitofwork-associations.html', 'Doctrine: owning and inverse sides'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Remove the unused inverse side %s::$%s', $entityClass, $field),
];
