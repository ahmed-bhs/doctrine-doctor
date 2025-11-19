<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityHint
 * @var mixed $context
 */
['entity_hint' => $entityHint] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering
ob_start();
?>

<div class="suggestion-header">
    <h4>setMaxResults() with collection join</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Data loss risk</strong> - <code>setMaxResults()</code> with fetch-joined collections applies LIMIT to SQL rows, not entities, causing incomplete collections.
    </div>

    <p>LIMIT applies to rows, not entities. If an order has 5 items, you might only get 2.</p>

    <h4>Current (incomplete)</h4>
    <pre><code class="language-php">$query = $em->createQueryBuilder()
    ->select('order', 'items')
    ->from(Order::class, 'order')
    ->leftJoin('order.items', 'items')
    ->setMaxResults(10)  // Wrong!
    ->getQuery();</code></pre>

    <h4>Solution: Use Paginator</h4>
    <pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\Paginator;

$paginator = new Paginator($query, $fetchJoinCollection = true);
$orders = iterator_to_array($paginator);</code></pre>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/pagination.html" target="_blank" class="doc-link">
            📖 Doctrine pagination docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use Paginator with setMaxResults() and collection joins to prevent data loss',
];
