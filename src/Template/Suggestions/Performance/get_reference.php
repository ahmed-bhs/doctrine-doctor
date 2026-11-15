<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entity      = (string) ($context['entity'] ?? 'Entity');
$occurrences = (int) ($context['occurrences'] ?? 0);
$severity    = (string) ($context['severity'] ?? 'info');
$e           = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<?php echo suggestionHeader('Use getReference() to set a relation'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>">
    <code>find()</code> loads <code><?php echo $e($entity); ?></code> in <?php echo $occurrences; ?> <?php echo 1 === $occurrences ? 'place' : 'places'; ?> only to assign it to a relation.
    <code>getReference()</code> returns a proxy holding the identifier, without a query.
</div>

<div class="query-item"><pre><code class="language-php">// Before: SELECT to set a foreign key
$order-&gt;setUser($em-&gt;find(User::class, $userId));

// After: no query until a field of the proxy is read
$order-&gt;setUser($em-&gt;getReference(User::class, $userId));</code></pre></div>

<p>Keep <code>find()</code> when you read the entity or need to check that it exists.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-objects.html#entity-object-graph-traversal', 'Doctrine getReference() docs'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Use getReference() instead of find() for %s (%d occurrences)', $entity, $occurrences)];
