<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entity
 * @var mixed $occurrences
 * @var mixed $context
 */
['entity' => $entity, 'occurrences' => $occurrences] = $context;
$e                                                   = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>

<div class="suggestion-header">
    <h4>Use getReference() for better performance</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Found <strong><?php echo $occurrences; ?></strong> <?php echo $occurrences > 1 ? 'places' : 'place'; ?> where <code>find()</code> is used just to set a relationship.
    </div>

    <p>When you only need to set a foreign key, <code>find()</code> wastes a query. Use <code>getReference()</code> instead — it creates a proxy without hitting the database.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">// Triggers a SELECT query
$user = $em->find(User::class, $userId);
$order->setUser($user);</code></pre>
    </div>

    <h4>Use getReference()</h4>
    <div class="query-item">
        <pre><code class="language-php">// No query until you access properties
$user = $em->getReference(User::class, $userId);
$order->setUser($user);</code></pre>
    </div>

    <p>Use <code>find()</code> when you need to access the entity's data or validate it exists. Use <code>getReference()</code> when you only need the ID for a relationship.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-objects.html#entity-object-graph-traversal" target="_blank" class="doc-link">
            📖 Doctrine getReference() docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Consider getReference() instead of find() for %s (%d occurrences)', $entity, $occurrences)];
