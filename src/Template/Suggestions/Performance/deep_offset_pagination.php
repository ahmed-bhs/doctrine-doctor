<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$offset = $context['offset'] ?? 0;
$originalQuery = $context['original_query'] ?? '';
// ORM 3.7 ships a CursorPaginator for keyset pagination
$cursorPaginatorAvailable = true === ($context['cursor_paginator_available'] ?? false);
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Paginate with a cursor, not an OFFSET'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?= t('OFFSET %d makes the database read and discard %d rows before returning the page.', (int) $offset, (int) $offset) ?>
    <?= t('Filtering on the last seen sort key reads only the rows of the page.') ?>
</div>

<div class="query-item"><pre><code class="language-php">// Before
$qb-&gt;orderBy('u.id', 'ASC')-&gt;setFirstResult(<?= (int) $offset ?>)-&gt;setMaxResults(20);

// After: keyset pagination on the last seen id
$qb-&gt;where('u.id &gt; :lastId')
   -&gt;setParameter('lastId', $lastSeenId)
   -&gt;orderBy('u.id', 'ASC')
   -&gt;setMaxResults(20);</code></pre></div>

<?php if ($cursorPaginatorAvailable) { ?>
<p><?= t('ORM 3.7+ builds this clause for you with <code>CursorPaginator</code>, from a deterministic ORDER BY:') ?></p>
<div class="query-item"><pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\CursorPaginator;

$page = (new CursorPaginator(20))-&gt;paginate($qb, $request-&gt;query-&gt;get('cursor'));
$nextCursor = $page-&gt;hasNextPage() ? $page-&gt;getNextCursorAsString() : null;</code></pre></div>
<?php } else { ?>
<p><?= t('If OFFSET must stay, cap the page number and index the ORDER BY column.') ?></p>
<?php } ?>

<?php echo suggestionDocLink('https://use-the-index-luke.com/no-offset', 'Use The Index, Luke! No Offset'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => t('Replace deep OFFSET pagination with keyset pagination on an indexed sort column'),
];
