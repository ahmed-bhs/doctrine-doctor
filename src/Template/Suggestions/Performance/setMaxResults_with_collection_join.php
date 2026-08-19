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

    <p>
        A fetch-joined to-many association returns one SQL row per combination, so
        <code>N</code> root entities become <code>N &times; factor</code> rows. LIMIT cuts that
        row stream, not the entity list. Detected on <code><?php echo $e(is_string($entityHint) ? $entityHint : 'entity'); ?></code>.
    </p>

    <h4>Symptom 1: truncated collections</h4>
    <pre><code class="language-php">$query = $em->createQueryBuilder()
    ->select('order', 'items')
    ->from(Order::class, 'order')
    ->leftJoin('order.items', 'items')
    ->setMaxResults(10)  // Wrong: 10 rows, not 10 orders
    ->getQuery();</code></pre>
    <p>You asked for 10 orders and got fewer, some carrying an incomplete <code>items</code> collection.</p>

    <h4>Symptom 2: root entities never read (worse)</h4>
    <pre><code class="language-php">$total  = $repository->count([]);   // counts ENTITIES
$offset = 0;

while ($offset &lt; $total) {
    $batch = $qb->leftJoin('r.tags', 't')->addSelect('t')
        ->setFirstResult($offset)     // walks ROWS
        ->setMaxResults(200)
        ->getQuery()->getResult();

    $offset += 200;                   // advances by ROWS
}</code></pre>
    <p>
        Say each entity spans 40 rows: the first pass consumes 200 rows but yields only 5 entities.
        <code>$offset</code> jumps to 200 while the <code>while</code> bound is the entity total, so the
        loop exits and every remaining entity is skipped. Nothing throws -- the batch just ends early.
    </p>

    <h4>Fix 1: paginate on identifiers, then fetch-join the batch</h4>
    <pre><code class="language-php">$ids = $qb->select('r.id')
    ->setFirstResult($offset)
    ->setMaxResults($batchSize)
    ->getQuery()
    ->getSingleColumnResult();   // no join: LIMIT counts entities

$batch = $qb2->leftJoin('r.tags', 't')->addSelect('t')
    ->where('r.id IN (:ids)')
    ->setParameter('ids', $ids)
    ->getQuery()
    ->getResult();               // join, no LIMIT</code></pre>
    <p>Preferred inside a batch loop: the identifier query stays cheap and the offset counts entities.</p>

    <h4>Fix 2: Doctrine Paginator</h4>
    <pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\Paginator;

$paginator = new Paginator($query, $fetchJoinCollection = true);
$orders = iterator_to_array($paginator);</code></pre>
    <p>
        Runs the same two-query strategy for you, plus a <code>COUNT</code> for
        <code>count($paginator)</code>. Best for page-by-page UI listings; in a large batch loop the
        extra COUNT per iteration is wasted work, so prefer Fix 1 there.
    </p>

    <div class="alert alert-info">
        <strong>Rule of thumb</strong> - LIMIT and <code>addSelect()</code> on a to-many association do
        not belong in one query. To-<em>one</em> joins (<code>ManyToOne</code>, <code>OneToOne</code>)
        add no rows and stay safe with LIMIT.
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/pagination.html" target="_blank" class="doc-link">
            📜 Doctrine pagination docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use Paginator with setMaxResults() and collection joins to prevent data loss',
];
