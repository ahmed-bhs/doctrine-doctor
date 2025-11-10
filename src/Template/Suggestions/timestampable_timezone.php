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
    <h4>Missing Timezone Information</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        🌍 <strong>Timezone Issue</strong><br>
        Field <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> uses <code>datetime</code> type.<br>
        Timezone information is lost, causing issues in multi-timezone applications.
    </div>

    <h4>The Problem</h4>
    <div class="query-item">
        <pre><code class="language-php">// CURRENT: No timezone stored
#[ORM\Column(type: 'datetime')]
private \DateTime $<?php echo $e($fieldName); ?>;

// Example:
// User in New York creates record at 14:00 EST
// Stored in DB: 2024-01-15 14:00:00 (no timezone!)
// User in Paris sees: 2024-01-15 14:00:00 CET (wrong time!)
// Should be: 2024-01-15 20:00:00 CET</code></pre>
    </div>

    <h4>Solution 1: Use datetimetz_immutable</h4>
    <div class="query-item">
        <pre><code class="language-php">// GOOD: Timezone-aware
#[ORM\Column(type: 'datetimetz_immutable')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// With Gedmo
#[ORM\Column(type: 'datetimetz_immutable')]
#[Gedmo\Timestampable(on: 'create')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// Stored in DB with timezone: 2024-01-15 14:00:00-05:00
// Correctly displayed in any timezone!</code></pre>
    </div>

    <h4>Solution 2: Store in UTC (Recommended)</h4>
    <div class="query-item">
        <pre><code class="language-php">// Alternative: Store everything in UTC
#[ORM\Column(type: 'datetime_immutable')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

#[ORM\PrePersist]
public function onCreate(): void
{
    // Always store in UTC
    $this-><?php echo $e($fieldName); ?> = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
}

// Display in user's timezone
public function get<?php echo ucfirst($fieldName); ?>Display(string $userTimezone): string
{
    return $this-><?php echo $e($fieldName); ?>
        ->setTimezone(new \DateTimeZone($userTimezone))
        ->format('Y-m-d H:i:s');
}</code></pre>
    </div>

    <h4>Comparison</h4>
    <table class="comparison-table">
        <tr>
            <th>Type</th>
            <th>Stores Timezone</th>
            <th>Best For</th>
        </tr>
        <tr>
            <td><code>datetime</code></td>
            <td>📢 No</td>
            <td>📢 Not recommended</td>
        </tr>
        <tr>
            <td><code>datetimetz</code></td>
            <td>Yes</td>
            <td>Multiple timezones, user preferences</td>
        </tr>
        <tr>
            <td><code>datetime</code> (UTC)</td>
            <td>📢 No (but stored in UTC)</td>
            <td>Simpler, convert on display</td>
        </tr>
    </table>

    <h4>Migration</h4>
    <div class="query-item">
        <pre><code class="language-sql">-- Migration to datetimetz
ALTER TABLE products
    ALTER COLUMN <?php echo strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $fieldName)); ?>
    TYPE TIMESTAMPTZ
    USING <?php echo strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $fieldName)); ?> AT TIME ZONE 'UTC';

-- Or for MySQL (limited timezone support)
ALTER TABLE products
    MODIFY <?php echo strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $fieldName)); ?>
    TIMESTAMP;</code></pre>
    </div>

    <div class="alert alert-info">
        💡 <strong>Recommendation:</strong> Use <code>datetimetz_immutable</code> for new projects.<br>
        For existing projects with UTC, you can keep <code>datetime_immutable</code> but document the UTC convention.
    </div>

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
