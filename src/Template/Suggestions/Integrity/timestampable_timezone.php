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
    <h4>Missing timezone information</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code>datetime</code> without timezone causes issues across timezones. Store in UTC and convert for display.
    </div>

    <h4>Solution: Store in UTC</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'datetime_immutable')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

#[ORM\PrePersist]
public function onCreate(): void
{
    $this-><?php echo $e($fieldName); ?> = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
}

public function get<?php echo ucfirst($fieldName); ?>Display(string $userTimezone): string
{
    return $this-><?php echo $e($fieldName); ?>
        ->setTimezone(new \DateTimeZone($userTimezone))
        ->format('Y-m-d H:i:s');
}</code></pre>
    </div>

    <p>Or use <code>datetimetz_immutable</code> to preserve original timezone.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#datetimetz" target="_blank" class="doc-link">
            📖 Doctrine DateTimeTZ →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add timezone support using datetimetz type',
];
