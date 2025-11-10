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
    <h4>Mutable DateTime in Timestamp Field</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        🕐 <strong>Mutability Issue</strong><br>
        Field <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> uses mutable DateTime.<br>
        This can cause unexpected bugs when the object is accidentally modified.
    </div>

    <h4>The Problem</h4>
    <div class="query-item">
        <pre><code class="language-php">// CURRENT: Mutable DateTime
#[ORM\Column(type: 'datetime')]
private \DateTime $<?php echo $e($fieldName); ?>;

// Example of the problem:
$date = $entity->get<?php echo ucfirst($fieldName); ?>();
$date->modify('+1 day'); // 😱 Modifies the entity's timestamp!
// Now the entity has been changed without using a setter</code></pre>
    </div>

    <h4>Solution: Use DateTimeImmutable</h4>
    <div class="query-item">
        <pre><code class="language-php">// GOOD: Immutable DateTime
#[ORM\Column(type: 'datetime_immutable')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// With Gedmo
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Column(type: 'datetime_immutable')]
#[Gedmo\Timestampable(on: 'create')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// With KnpLabs (supports DateTimeImmutable by default)
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableEntity;

class <?php echo $e($entityClass); ?>
{
    use TimestampableEntity; // Uses DateTimeImmutable
}

// Manual implementation
#[ORM\Column(type: 'datetime_immutable', nullable: false)]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

#[ORM\PrePersist]
public function onCreate(): void
{
    $this-><?php echo $e($fieldName); ?> = new \DateTimeImmutable();
}

// Safe usage:
$date = $entity->get<?php echo ucfirst($fieldName); ?>();
$newDate = $date->modify('+1 day'); // Returns NEW instance
// $entity-><?php echo $e($fieldName); ?> is unchanged! </code></pre>
    </div>

    <h4>Why DateTimeImmutable?</h4>
    <ul>
        <li><strong>No Side Effects:</strong> Cannot be modified accidentally</li>
        <li><strong>Thread Safe:</strong> Safe in concurrent contexts</li>
        <li><strong>Predictable:</strong> Value never changes after creation</li>
        <li><strong>Best Practice:</strong> Recommended by Doctrine team</li>
    </ul>

    <h4>Migration</h4>
    <div class="query-item">
        <pre><code class="language-php">// No database migration needed!
// Doctrine handles DateTime and DateTimeImmutable the same way

// Just update your entity:
- private \DateTime $<?php echo $e($fieldName); ?>;
+ private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// Update type hint in getter:
- public function get<?php echo ucfirst($fieldName); ?>(): \DateTime
+ public function get<?php echo ucfirst($fieldName); ?>(): \DateTimeImmutable
{
    return $this-><?php echo $e($fieldName); ?>;
}</code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/cookbook/working-with-datetime.html" target="_blank" class="doc-link">
            📖 Doctrine: Working with DateTime →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Replace mutable DateTime with DateTimeImmutable',
];
