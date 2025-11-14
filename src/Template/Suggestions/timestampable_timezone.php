<?php

declare(strict_types=1);

/**
 * Template for Timestampable Missing Timezone.
 * Context variables:
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
        <strong><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></strong> uses <code>datetime</code> without timezone info. This causes issues when users are in different timezones.
    </div>

    <p>When you store <code>2024-01-15 14:00:00</code> without timezone info, is that EST or CET? The database doesn't know, so a user in New York and a user in Paris will see different times for the same event.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'datetime')]
private \DateTime $<?php echo $e($fieldName); ?>;</code></pre>
    </div>

    <h4>Option 1: Store with timezone</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'datetimetz_immutable')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// Stored as: 2024-01-15 14:00:00-05:00
// Displays correctly in any timezone</code></pre>
    </div>

    <h4>Option 2: Store in UTC</h4>
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

    <p>Most applications store everything in UTC and convert to the user's timezone when displaying. This is simpler and works well. Use <code>datetimetz</code> if you need to preserve the original timezone.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#datetimetz" target="_blank" class="doc-link">
            📖 Doctrine: DateTimeTZ Type →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add timezone support using datetimetz type',
];
