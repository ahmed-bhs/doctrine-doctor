<?php

declare(strict_types=1);

/**
 * @var string $entity_class Entity class name
 * @var string $field_name   Field name
 */
$entityClass = $context['entity_class'] ?? '';
$entityClass = is_string($entityClass) ? $entityClass : '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<?php echo suggestionHeader('Make the creator column NOT NULL'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> is nullable, so a row can be saved without knowing who created it. Require the creator in the constructor and drop the public setter.</p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
class <?php echo $e($shortClass); ?>

{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?php echo $e($fieldName); ?>;

    public function __construct(User $<?php echo $e($fieldName); ?>)
    {
        $this-><?php echo $e($fieldName); ?> = $<?php echo $e($fieldName); ?>;
    }
}</code></pre></div>

<?php echo suggestionDocLink('https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/blameable.md', 'Doctrine Extensions Blameable'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Make createdBy non-nullable for reliable audit data',
];
