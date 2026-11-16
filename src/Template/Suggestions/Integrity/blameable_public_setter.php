<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$entityClass = is_string($entityClass) ? $entityClass : '';
$fieldName = $context['field_name'] ?? '';

$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<?php echo suggestionHeader('Remove the setter on the audit field'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>"><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> has a public setter, so any code can rewrite who created the entity. Set it once in the constructor.</div>

<div class="query-item"><pre><code class="language-php">class <?php echo $e($shortClass); ?>

{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?php echo $e($fieldName); ?>;

    public function __construct(User $<?php echo $e($fieldName); ?>)
    {
        $this-><?php echo $e($fieldName); ?> = $<?php echo $e($fieldName); ?>;
    }

    // Before: public function set<?php echo $e(ucfirst($fieldName)); ?>(User $user): void
}</code></pre></div>

<?php echo suggestionDocLink('https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/blameable.md', 'Doctrine Extensions Blameable'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Remove public setter on blameable field %s::%s', $shortClass, $fieldName),
];
