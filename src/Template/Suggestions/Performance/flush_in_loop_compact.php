<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$flushCount = max(0, (int) ($context['flush_count'] ?? 0));
$operationsBetweenFlush = max(0, (int) ($context['operations_between_flush'] ?? 20));

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Flush in batches, not in the loop'); ?>
<div class="suggestion-content">
<p><?php echo t('flush() ran %d times inside a loop; each call computes the change set and opens a transaction.', $flushCount); ?></p>
<div class="query-item"><pre><code class="language-php">// Before
foreach ($items as $item) {
    $em-&gt;persist($item);
    $em-&gt;flush();
}

// After: one flush per 20 entities
foreach ($items as $i =&gt; $item) {
    $em-&gt;persist($item);
    if (0 === ($i + 1) % 20) {
        $em-&gt;flush();
        $em-&gt;clear();
    }
}
$em-&gt;flush();</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/batch-processing.html', 'Doctrine batch processing'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Flush in batches instead of %d times in a loop', $flushCount),
];
