<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
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
<?php echo suggestionHeader('Initialize the collection in the constructor'); ?>
<div class="suggestion-content">
<p><?php echo $hasConstructor
    ? t('<code>%s::$%s</code> is never initialized by the existing constructor. On a new entity, calling <code>add()</code> or <code>count()</code> on it throws an Error.', $e($shortClass), $e($fieldName))
    : t('<code>%s::$%s</code> is never initialized, and the entity has no constructor. On a new entity, calling <code>add()</code> or <code>count()</code> on it throws an Error.', $e($shortClass), $e($fieldName)); ?></p>

<div class="query-item"><pre><code class="language-php">use Doctrine\Common\Collections\ArrayCollection;

class <?php echo $e($shortClass); ?> {
    #[ORM\<?php echo $e($associationType); ?>(targetEntity: <?php echo $e($targetEntity); ?>::class<?php echo $e($mappedBy); ?>)]
    private Collection $<?php echo $e($fieldName); ?>;

    public function __construct() {
        $this-><?php echo $e($fieldName); ?> = new ArrayCollection();
    }
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html', 'Doctrine ORM Working with Associations'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => t('Initialize %s::$%s in constructor', $shortClass, $fieldName)];
