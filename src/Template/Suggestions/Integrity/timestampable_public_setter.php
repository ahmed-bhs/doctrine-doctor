<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $fieldName
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$fieldName   = (string) ($context['field_name'] ?? 'created_at');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<?php echo suggestionHeader('Public setter on timestamp field'); ?>

<div class="suggestion-content">
    <div class="alert alert-info">
        <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> has a public setter.
    </div>

    <p>Timestamps should be managed automatically.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

    public function set<?php echo $e(ucfirst((string) $fieldName)); ?>(\DateTimeImmutable $date): void {
        $this-><?php echo $e($fieldName); ?> = $date;
    }
}</code></pre>
    </div>

    <h4>Fix</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

    public function get<?php echo $e(ucfirst((string) $fieldName)); ?>(): \DateTimeImmutable {
        return $this-><?php echo $e($fieldName); ?>;
    }
}</code></pre>
    </div>

    <p>Remove the setter.</p>

    <?php echo suggestionDocLink('https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/timestampable.md', 'Doctrine Extensions Timestampable'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove public setter on timestamp field',
];
