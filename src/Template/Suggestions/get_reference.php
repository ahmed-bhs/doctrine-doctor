<?php

declare(strict_types=1);

/**
 * Template for getReference() best practice suggestions.
 * Context variables:
 * @var string $entity - Entity class name
 * @var int    $occurrences - Number of times this pattern was found
 */
['entity' => $entity, 'occurrences' => $occurrences] = $context;
$e                                                   = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>

<div class="suggestion-header">
    <h4>Use getReference() for Better Performance</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        💡 <strong>Performance Tip</strong><br>
        Found <strong><?php echo $occurrences; ?></strong> <?php echo $occurrences > 1 ? 'places' : 'place'; ?> where <code>find()</code> is used just to set a relationship.
    </div>

    <h4>Current Approach</h4>
    <div class="query-item">
        <pre><code class="language-php">// Triggers unnecessary SELECT query
$user = $em->find(User::class, $userId);
$order->setUser($user);</code></pre>
    </div>

    <h4> Better Approach</h4>
    <div class="query-item">
        <pre><code class="language-php">//  No SELECT query! Creates a proxy
$user = $em->getReference(User::class, $userId);
$order->setUser($user);
// Query only executes if you access $user properties</code></pre>
    </div>

    <h4>When to use getReference()</h4>
    <ul>
        <li> Setting relationships (foreign keys)</li>
        <li> You only need the ID, not the full object</li>
        <li>You need to access object properties immediately</li>
        <li>The entity might not exist (use find() for validation)</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ Performance impact: Eliminates <?php echo $occurrences; ?> unnecessary SELECT <?php echo $occurrences > 1 ? 'queries' : 'query'; ?>!
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-objects.html#entity-object-graph-traversal" target="_blank" class="doc-link">
            📖 Doctrine getReference() Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Use getReference() instead of find() for %s (%d occurrences)', $entity, $occurrences)];
