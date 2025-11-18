<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $fieldName
 * @var mixed $context
 */
['entity_class' => $entityClass, 'field_name' => $fieldName] = $context;
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Public setter on timestamp field</h4>
</div>

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

    public function set<?php echo ucfirst($fieldName); ?>(\DateTimeImmutable $date): void {
        $this-><?php echo $e($fieldName); ?> = $date;
    }
}</code></pre>
    </div>

    <h4>Fix</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

    public function get<?php echo ucfirst($fieldName); ?>(): \DateTimeImmutable {
        return $this-><?php echo $e($fieldName); ?>;
    }
}</code></pre>
    </div>

    <p>Remove the setter.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove public setter on timestamp field',
];
