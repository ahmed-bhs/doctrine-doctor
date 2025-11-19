<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $fieldName
 * @var mixed $hasConstructor
 * @var mixed $context
 */
['entity_class' => $entityClass, 'field_name' => $fieldName, 'has_constructor' => $hasConstructor] = $context;
$e                                                                                                 = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                     = strrchr($entityClass, '\\');
$shortClass                                                                                        = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
ob_start();
?>
<div class="suggestion-header"><h4>Uninitialized collection</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><strong><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></strong> is not initialized</div>

<p>Collections need to be initialized in the constructor. Without this, calling add/remove methods will throw an error.</p>

<h4>Current code</h4>
<div class="query-item"><pre><code class="language-php">class <?php echo $e($shortClass); ?> {
    #[ORM\OneToMany(targetEntity: Item::class, mappedBy: 'parent')]
    private Collection $<?php echo $e($fieldName); ?>;
}</code></pre></div>

<h4>Fix</h4>
<div class="query-item"><pre><code class="language-php">use Doctrine\Common\Collections\ArrayCollection;

class <?php echo $e($shortClass); ?> {
    #[ORM\OneToMany(targetEntity: Item::class, mappedBy: 'parent')]
    private Collection $<?php echo $e($fieldName); ?>;

    public function __construct() {
        $this-><?php echo $e($fieldName); ?> = new ArrayCollection();
    }
}</code></pre></div>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Initialize %s::$%s in constructor', $shortClass, $fieldName)];
