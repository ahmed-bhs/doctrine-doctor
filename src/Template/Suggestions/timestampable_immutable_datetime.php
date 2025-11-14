<?php

declare(strict_types=1);

/**
 * Template for Timestampable Immutable DateTime.
 * Context variables:
 */
['entity_class' => $entityClass, 'field_name' => $fieldName] = $context;
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Mutable DateTime in timestamp field</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> uses mutable DateTime. This can cause bugs when the object is accidentally modified.
    </div>

    <p>When you return a DateTime from a getter, external code can modify it without going through your entity's setters. This breaks encapsulation and can lead to hard-to-debug issues.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'datetime')]
private \DateTime $<?php echo $e($fieldName); ?>;

// Problem:
$date = $entity->get<?php echo ucfirst($fieldName); ?>();
$date->modify('+1 day'); // Modifies the entity directly
</code></pre>
    </div>

    <h4>Use DateTimeImmutable</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'datetime_immutable')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// With Gedmo
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Column(type: 'datetime_immutable')]
#[Gedmo\Timestampable(on: 'create')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// Manual implementation
#[ORM\PrePersist]
public function onCreate(): void
{
    $this-><?php echo $e($fieldName); ?> = new \DateTimeImmutable();
}

// Now safe:
$date = $entity->get<?php echo ucfirst($fieldName); ?>();
$newDate = $date->modify('+1 day'); // Returns new instance
// Entity is unchanged</code></pre>
    </div>

    <p>No database migration needed - Doctrine handles both types the same way. Just update the PHP type and you're done.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/cookbook/working-with-datetime.html" target="_blank" class="doc-link">
            📖 Doctrine datetime docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Replace DateTime with DateTimeImmutable',
];
