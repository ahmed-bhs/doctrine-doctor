<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$fieldName = (string) ($context['field_name'] ?? 'items');
$targetEntity = (string) ($context['target_entity'] ?? 'Item');
$associationType = (string) ($context['association_type'] ?? 'OneToMany');
$mappedByValue = (string) ($context['mapped_by'] ?? 'yourPropertyName');
$hasConstructor = (bool) ($context['has_constructor'] ?? false);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash = strrchr($entityClass, '\\');
$shortClass = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
$mappedBy = 'OneToMany' === $associationType ? ", mappedBy: '" . $mappedByValue . "'" : '';
ob_start();
?>
<div class="suggestion-header"><h4>Uninitialized collection</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><strong><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></strong> is not initialized</div>

<?php if ($hasConstructor): ?>
<p>The constructor exists but does not initialize <code>$<?php echo $e($fieldName); ?></code>. Add the initialization or use constructor promotion.</p>
<?php else: ?>
<p>This entity has no constructor. Collections must be initialized to avoid null pointer errors.</p>
<?php endif; ?>

<h4>Current code</h4>
<div class="query-item"><pre><code class="language-php">class <?php echo $e($shortClass); ?> {
    #[ORM\<?php echo $e($associationType); ?>(targetEntity: <?php echo $e($targetEntity); ?>::class<?php echo $e($mappedBy); ?>)]
    private Collection $<?php echo $e($fieldName); ?>;
<?php if ($hasConstructor): ?>

    public function __construct() {
        // $<?php echo $e($fieldName); ?> is not initialized here
    }
<?php endif; ?>
}</code></pre></div>

<h4>Option 1 — Constructor initialization</h4>
<div class="query-item"><pre><code class="language-php">use Doctrine\Common\Collections\ArrayCollection;

class <?php echo $e($shortClass); ?> {
    #[ORM\<?php echo $e($associationType); ?>(targetEntity: <?php echo $e($targetEntity); ?>::class<?php echo $e($mappedBy); ?>)]
    private Collection $<?php echo $e($fieldName); ?>;

    public function __construct() {
        $this-><?php echo $e($fieldName); ?> = new ArrayCollection();
    }
}</code></pre></div>

<h4>Option 2 — Constructor promotion (PHP 8.1+)</h4>
<div class="query-item"><pre><code class="language-php">use Doctrine\Common\Collections\ArrayCollection;

class <?php echo $e($shortClass); ?> {
    public function __construct(
        #[ORM\<?php echo $e($associationType); ?>(targetEntity: <?php echo $e($targetEntity); ?>::class<?php echo $e($mappedBy); ?>)]
        private Collection $<?php echo $e($fieldName); ?> = new ArrayCollection(),
    ) {
    }
}</code></pre></div>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Working with Associations</a></p>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Initialize %s::$%s in constructor', $shortClass, $fieldName)];
