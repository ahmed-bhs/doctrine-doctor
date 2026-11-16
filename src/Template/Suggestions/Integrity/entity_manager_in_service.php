<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$className = $context['class_name'] ?? 'OrderService';
$parameter = $context['parameter'] ?? 'entityManager';
$repositories = $context['repositories'] ?? [];
$entity = is_array($repositories) && isset($repositories[0]) ? (string) $repositories[0] : 'Order';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Depend on a repository, not on the EntityManager'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($className); ?></code> can receive the repositories it reads from. When the business code must not know Doctrine at all, type it against an interface of your domain and let the Doctrine repository implement it.</p>

<div class="query-item"><pre><code class="language-php">// Before
public function __construct(private EntityManagerInterface $<?php echo $e($parameter); ?>) {}
$<?php echo $e(lcfirst($entity)); ?> = $this-><?php echo $e($parameter); ?>->getRepository(<?php echo $e($entity); ?>::class)->find($id);

// After
public function __construct(private <?php echo $e($entity); ?>Repository $<?php echo $e(lcfirst($entity)); ?>s) {}
$<?php echo $e(lcfirst($entity)); ?> = $this-><?php echo $e(lcfirst($entity)); ?>s->find($id);</code></pre></div>

<p>Writes can go through a method of the repository, such as <code>save(<?php echo $e($entity); ?> $<?php echo $e(lcfirst($entity)); ?>)</code>, so that persist() and flush() stay in one place.</p>

<?php echo suggestionDocLink('https://symfony.com/doc/current/doctrine.html#querying-for-objects-the-repository', 'Symfony: querying with a repository'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Inject repositories into %s instead of the EntityManager', $className),
];
