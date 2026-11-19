<?php

declare(strict_types=1);

/**
 * Template for write statements run outside any transaction.
 * Context variables:
 * - count: int - Number of write statements
 * - total_ms: float - Their total execution time
 * - table: string - Table of the first statement
 */
$count = (int) ($context['count'] ?? 0);
$totalMs = (float) ($context['total_ms'] ?? 0.0);
$table = (string) ($context['table'] ?? 'the table');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Wrap the writes in one transaction'); ?>
<div class="suggestion-content">
<p><?php echo t('%d write statements into <code>%s</code> each committed on their own (%s ms in total).', $count, $e($table), $e(sprintf('%.2f', $totalMs))); ?>
<?php echo t('In autocommit mode, every commit waits for the database to flush its log to disk. One transaction pays that cost once.'); ?></p>

<div class="query-item"><pre><code class="language-php">// With the ORM: flush once, or in batches inside a transaction
$entityManager-&gt;wrapInTransaction(function () use ($entityManager, $rows): void {
    foreach ($rows as $row) {
        $entityManager-&gt;persist($row);
    }
});

// With DBAL
$connection-&gt;transactional(function () use ($connection, $rows): void {
    foreach ($rows as $row) {
        $connection-&gt;insert('<?php echo $e($table); ?>', $row);
    }
});</code></pre></div>

<p><?php echo t('One transaction is also all or nothing: an error halfway leaves no partial import. For tens of thousands of rows, commit every few thousand so locks and undo logs stay small.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/transactions-and-concurrency.html', 'Doctrine ORM: Transactions and Concurrency'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Wrap %d writes in one transaction', $count),
];
