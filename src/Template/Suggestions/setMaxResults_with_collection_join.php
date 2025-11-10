<?php

declare(strict_types=1);

/**
 * Template for setMaxResults() with Collection Join suggestions.
 * Context variables:
 * @var string $entity_hint - Entity name hint from SQL analysis
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
['entity_hint' => $entityHint] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path fill-rule="evenodd" d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Critical: setMaxResults() with Collection Join</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        🚨 <strong>Silent Data Loss Risk!</strong><br>
        Using <code>setMaxResults()</code> with fetch-joined collections causes LIMIT to apply to SQL rows instead of entities,
        resulting in <strong>incomplete collections</strong>.
    </div>

    <h4>Problem Example</h4>
    <div class="query-item">
        <pre><code class="language-php">// DANGEROUS: Partial collection hydration
$query = $em->createQueryBuilder()
    ->select('order', 'items')
    ->from(Order::class, 'order')
    ->leftJoin('order.items', 'items')
    ->setMaxResults(10)  // LIMIT applies to SQL rows, not orders!
    ->getQuery();

$orders = $query->getResult();
// If Order #1 has 5 items, only 2 may be loaded!</code></pre>
    </div>

    <h4> Solution: Use Doctrine Paginator</h4>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\Paginator;

$query = $em->createQueryBuilder()
    ->select('order', 'items')
    ->from(Order::class, 'order')
    ->leftJoin('order.items', 'items')
    ->setMaxResults(10)
    ->getQuery();

// Paginator executes 2 queries to load complete collections
$paginator = new Paginator($query, $fetchJoinCollection = true);
$orders = iterator_to_array($paginator);
// ✓ All order items loaded correctly</code></pre>
    </div>

    <div class="alert alert-info">
        <strong>Impact:</strong> Without Paginator = silent data loss. With Paginator = 100% data integrity (2 optimized queries).
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/pagination.html" target="_blank" class="doc-link">
            📖 Doctrine Pagination Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use Doctrine Paginator when combining setMaxResults() with collection joins to prevent partial collection hydration and silent data loss.',
];
