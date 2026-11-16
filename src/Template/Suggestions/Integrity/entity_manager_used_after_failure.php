<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$method = $context['method'] ?? 'Importer::import';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Reset the EntityManager or stop after a failed flush'); ?>
<div class="suggestion-content">
<p>After a failed <code>flush()</code> in <code><?php echo $e($method); ?>()</code>, the EntityManager is closed and its pending changes are lost. Either stop, or get a fresh EntityManager from the registry before going on.</p>

<div class="query-item"><pre><code class="language-php">foreach ($rows as $row) {
    try {
        $em->persist(Product::fromCsv($row));
        $em->flush();
    } catch (UniqueConstraintViolationException $exception) {
        $logger->warning($exception->getMessage());
        $em = $registry->resetManager();
    }
}</code></pre></div>

<p>Check a row before persisting it when you can, so that a duplicate does not reach the database at all.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/transactions-and-concurrency.html#exception-handling', 'Doctrine: exception handling'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Do not reuse the EntityManager after a failed flush in %s()', $method),
];
