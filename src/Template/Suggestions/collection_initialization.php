<?php

declare(strict_types=1);

['entity_class' => $entityClass, 'field_name' => $fieldName, 'has_constructor' => $hasConstructor] = $context;
$e                                                                                                 = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                     = strrchr($entityClass, '\\');
$shortClass                                                                                        = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
ob_start();
?>
<div class="suggestion-header"><h4>Uninitialized Collection</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><strong>CRITICAL: Uninitialized Collection in <?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></strong></div>
<h4>Problem</h4>
<div class="query-item"><pre><code class="language-php">class <?php echo $e($shortClass); ?> {
    #[ORM\OneToMany(targetEntity: Item::class, mappedBy: 'parent')]
    private Collection $<?php echo $e($fieldName); ?>;  // Not initialized!
}</code></pre></div>
<h4> Solution</h4>
<div class="query-item"><pre><code class="language-php">use Doctrine\Common\Collections\ArrayCollection;

class <?php echo $e($shortClass); ?> {
    #[ORM\OneToMany(targetEntity: Item::class, mappedBy: 'parent')]
    private Collection $<?php echo $e($fieldName); ?>;

    public function __construct() {
        $this-><?php echo $e($fieldName); ?> = new ArrayCollection();  //  Initialize!
    }
}</code></pre></div>
<p><strong>Why:</strong> Uninitialized collections cause null pointer exceptions when calling add/remove methods.</p>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Initialize collection %s::$%s in constructor', $shortClass, $fieldName)];
