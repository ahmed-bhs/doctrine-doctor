<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityHint
 * @var array<string, mixed> $context
 */
$entityHint = (string) ($context['entity_hint'] ?? 'Entity');
// ORM 3.7 deprecates Paginator in favour of OffsetPaginator
$offsetPaginatorAvailable = true === ($context['offset_paginator_available'] ?? false);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Paginate entities, not joined rows'); ?>
<div class="suggestion-content">
<div class="alert alert-danger">
    <?php echo t('With a fetch-joined collection on <code>%s</code>, <code>setMaxResults()</code> limits SQL rows, not entities.', $e($entityHint)); ?>
    <?php echo t('Fewer roots than requested come back and some collections are truncated.'); ?>
</div>

<?php if ($offsetPaginatorAvailable) { ?>
<div class="query-item"><pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;

// Before: LIMIT 10 on rows
$qb-&gt;leftJoin('o.items', 'i')-&gt;addSelect('i')-&gt;setMaxResults(10);

// After: no setMaxResults(), the Window carries offset and limit
$page = (new OffsetPaginator(fetchJoinCollection: true))
    -&gt;paginate($qb-&gt;getQuery(), new Window($offset, 10));
$orders = $page-&gt;getItems();</code></pre></div>
<?php } else { ?>
<div class="query-item"><pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\Paginator;

// Before: LIMIT 10 on rows
$qb-&gt;leftJoin('o.items', 'i')-&gt;addSelect('i')-&gt;setMaxResults(10);

// After: the Paginator limits identifiers first, then fetch-joins them
$qb-&gt;setFirstResult($offset)-&gt;setMaxResults(10);
$orders = iterator_to_array(new Paginator($qb-&gt;getQuery(), fetchJoinCollection: true));</code></pre></div>
<?php } ?>

<p><?php echo t('In a batch loop, skip the extra COUNT of the paginator: select a page of <code>id</code> values without the join, then fetch-join <code>WHERE o.id IN (:ids)</code>.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/pagination.html', 'Doctrine pagination docs'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Use a paginator so the limit applies to entities, not to fetch-joined rows'),
];
