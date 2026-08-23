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

<?php echo suggestionHeader('Missing timezone information'); ?>

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

public function get<?php echo ucfirst((string) $fieldName); ?>Display(string $userTimezone): string
{
    return $this-><?php echo $e($fieldName); ?>
        ->setTimezone(new \DateTimeZone($userTimezone))
        ->format('Y-m-d H:i:s');
}</code></pre>
    </div>

    <p>Or use <code>datetimetz_immutable</code> to preserve original timezone.</p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#datetimetz', 'Doctrine DateTimeTZ'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add timezone support using the datetimetz type',
];
