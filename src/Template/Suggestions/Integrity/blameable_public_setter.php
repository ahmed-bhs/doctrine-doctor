<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<div class="suggestion-header"><h4>Public setter on blameable field</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><code><?php echo $e($fieldName); ?></code> has a public setter, allowing the audit field to be changed.</div>

<p>Blameable fields should be set once and immutable.</p>

<h4>Fix</h4>
<div class="query-item"><pre><code class="language-php">class <?php echo $e($shortClass); ?>

{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?php echo $e($fieldName); ?>;

    public function __construct(User $<?php echo $e($fieldName); ?>)
    {
        $this-><?php echo $e($fieldName); ?> = $<?php echo $e($fieldName); ?>;
    }

    public function get<?php echo ucfirst($fieldName); ?>(): User
    {
        return $this-><?php echo $e($fieldName); ?>;
    }
}</code></pre></div>

<p>Remove the setter. Set in constructor.</p>

<p><a href="https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/blameable.md" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine Extensions Blameable</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Remove public setter on blameable field %s::%s', $shortClass, $fieldName),
];
