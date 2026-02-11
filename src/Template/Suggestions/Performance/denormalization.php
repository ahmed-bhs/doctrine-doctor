<?php

declare(strict_types=1);

/**
 * Template for Denormalization (Counter Field) suggestions.
 * Context variables:
 * @var string $entity - Entity name (e.g., "Article")
 * @var string $relation - Relation name (e.g., "comments")
 * @var int    $query_count - Number of queries detected
 * @var string $counter_field - Suggested counter field name (e.g., "commentsCount")
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
[
    'entity' => $entity,
    'relation' => $relation,
    'query_count' => $queryCount,
    'counter_field' => $counterField,
] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Suggested Fix: Denormalization (Counter Field)</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Repeated count() Calls Detected</strong><br>
        Detected <strong><?php echo $queryCount; ?> repeated count() queries</strong> on <code><?php echo $e($relation); ?></code> collection.
        Instead of running <?php echo $queryCount; ?> COUNT queries, store the count in a denormalized field.
    </div>

    <h4>Problem: Counting Collection in Loop</h4>
    <div class="query-item">
        <pre><code class="language-php">// BAD: Even with EXTRA_LAZY, <?php echo $queryCount; ?> COUNT queries
$entities = $repository->findAll();
Assert::isIterable($entities, '$entities must be iterable');

foreach ($entities as $entity) {
    echo $entity->get<?php echo ucfirst($relation); ?>()->count(); // COUNT query!
}
// Result: <?php echo $queryCount; ?> COUNT queries</code></pre>
    </div>

    <h4>Solution: Store Counter in Entity (Denormalization)</h4>
    <div class="query-item">
        <pre><code class="language-php">// BEST: Store count directly in entity
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?php echo $e($entity); ?>

{
    #[ORM\Column(type: 'integer')]
    private int $<?php echo $e($counterField); ?> = 0;

    #[ORM\OneToMany(mappedBy: '<?php echo lcfirst($entity); ?>', cascade: ['persist', 'remove'])]
    private Collection $<?php echo $e($relation); ?>;

    public function get<?php echo ucfirst((string) $counterField); ?>(): int
    {
        return $this-><?php echo $e($counterField); ?>;
    }

    // Update counter when adding/removing items
    public function add<?php echo ucfirst(rtrim($relation, 's')); ?>(<?php echo ucfirst(rtrim($relation, 's')); ?> $item): self
    {
        if (!$this-><?php echo $e($relation); ?>->contains($item)) {
            $this-><?php echo $e($relation); ?>[] = $item;
            $item->set<?php echo $e($entity); ?>($this);
            ++$this-><?php echo $e($counterField); ?>; // ← Increment counter
        }

        return $this;
    }

    public function remove<?php echo ucfirst(rtrim($relation, 's')); ?>(<?php echo ucfirst(rtrim($relation, 's')); ?> $item): self
    {
        if ($this-><?php echo $e($relation); ?>->removeElement($item)) {
            if ($item->get<?php echo $e($entity); ?>() === $this) {
                $item->set<?php echo $e($entity); ?>(null);
            }
            --$this-><?php echo $e($counterField); ?>; // ← Decrement counter
        }

        return $this;
    }
}

// Now use the cached counter:
foreach ($entities as $entity) {
    echo $entity->get<?php echo ucfirst((string) $counterField); ?>(); // No query!
}
// Result: 0 extra queries!</code></pre>
    </div>

    <h4>Option 2: Doctrine Lifecycle Callbacks (Automatic)</h4>
    <div class="query-item">
        <pre><code class="language-php">// Alternative: Use lifecycle callbacks to auto-update counter
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class <?php echo $e($entity); ?>

{
    #[ORM\Column(type: 'integer')]
    private int $<?php echo $e($counterField); ?> = 0;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateCounters(): void
    {
        $this-><?php echo $e($counterField); ?> = $this-><?php echo $e($relation); ?>->count();
    }
}

// Counter automatically updated on save!</code></pre>
    </div>

    <h4>Option 3: Database Trigger (Most Reliable)</h4>
    <div class="query-item">
        <pre><code class="language-sql">-- Create trigger to auto-update counter (PostgreSQL example)
CREATE OR REPLACE FUNCTION update_<?php echo strtolower($entity); ?>_<?php echo $e($counterField); ?>()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        UPDATE <?php echo strtolower($entity); ?>
        SET <?php echo $e($counterField); ?> = <?php echo $e($counterField); ?> + 1
        WHERE id = NEW.<?php echo strtolower($entity); ?>_id;
    ELSIF TG_OP = 'DELETE' THEN
        UPDATE <?php echo strtolower($entity); ?>
        SET <?php echo $e($counterField); ?> = <?php echo $e($counterField); ?> - 1
        WHERE id = OLD.<?php echo strtolower($entity); ?>_id;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_<?php echo strtolower($entity); ?>_counter
AFTER INSERT OR DELETE ON <?php echo $e($relation); ?>

FOR EACH ROW EXECUTE FUNCTION update_<?php echo strtolower($entity); ?>_<?php echo $e($counterField); ?>();</code></pre>
    </div>

    <h4>⚖️ Trade-offs: Denormalization</h4>
    <div class="alert alert-warning">
        <strong>Pros:</strong>
        <ul>
            <li><strong>Zero queries for counts</strong>: Read directly from field</li>
            <li><strong>Fastest possible</strong>: No database round-trip needed</li>
            <li><strong>Scales perfectly</strong>: O(1) regardless of collection size</li>
            <li><strong>Sortable/filterable</strong>: Can ORDER BY <?php echo $e($counterField); ?> in queries</li>
        </ul>
        <strong>Cons:</strong>
        <ul>
            <li><strong>Data redundancy</strong>: Count stored in two places (counter + actual count)</li>
            <li><strong>Consistency risk</strong>: Counter can drift if not maintained properly</li>
            <li><strong>Extra maintenance</strong>: Must update counter on add/remove</li>
            <li><strong>Schema changes</strong>: Requires migration to add column</li>
        </ul>
    </div>

    <h4>When to Use Denormalization</h4>
    <ul>
        <li><strong>Frequent counts</strong>: You call count() often (like in listings)</li>
        <li><strong>Large collections</strong>: Collections with hundreds/thousands of items</li>
        <li><strong>Sorting by count</strong>: Need to ORDER BY count in queries</li>
        <li><strong>API responses</strong>: Returning counts in JSON responses</li>
    </ul>

    <h4>Best Practices</h4>
    <ul>
        <li>Use database triggers for 100% consistency (recommended)</li>
        <li>Add database constraint: CHECK (<?php echo $e($counterField); ?> >= 0)</li>
        <li>Write tests to verify counter accuracy</li>
        <li>Consider soft deletes impact on counters</li>
        <li>Add migration to populate existing counters:
            <pre><code>UPDATE <?php echo strtolower($entity); ?> SET <?php echo $e($counterField); ?> = (
    SELECT COUNT(*) FROM <?php echo $e($relation); ?> WHERE <?php echo strtolower($entity); ?>_id = <?php echo strtolower($entity); ?>.id
);</code></pre>
        </li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li><strong>Current:</strong> <?php echo $queryCount; ?> COUNT queries</li>
            <li><strong>With Denormalization:</strong> 0 queries (reads from column)</li>
            <li><strong>Query reduction:</strong> 100%</li>
            <li><strong>Response time:</strong> ~<?php echo $queryCount * 2; ?>ms saved (assuming 2ms/query)</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/cookbook/aggregate-fields.html" target="_blank" class="doc-link">
            📖 Doctrine Aggregate Fields Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Store %s count in a denormalized %s field to avoid repeated COUNT queries',
        $relation,
        $counterField,
    ),
];
