<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$table = is_string($context['table'] ?? null) ? $context['table'] : 'your_table';
$count = is_int($context['count'] ?? null) ? $context['count'] : 0;
$sql = is_string($context['sql'] ?? null) ? $context['sql'] : '';
$entity = is_string($context['entity'] ?? null) ? $context['entity'] : null;
$bypassed = is_array($context['bypassed'] ?? null) ? $context['bypassed'] : [];
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Insert rows in batches with DBAL'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?= t('%s single-row INSERT statements were sent to <code>%s</code>, one round trip each.', $e((string) $count), $e($table)) ?>
    <?= t('A multi-row INSERT through DBAL writes hundreds of rows per statement.') ?>
</div>

<div class="query-item"><pre><code class="language-php">$connection = $entityManager-&gt;getConnection();

$connection-&gt;transactional(function () use ($connection, $rows): void {
    foreach (array_chunk($rows, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?)'));

        $connection-&gt;executeStatement(
            'INSERT INTO <?= $e($table) ?> (name, email) VALUES ' . $placeholders,
            array_merge(...array_map(fn (array $row): array =&gt; [$row['name'], $row['email']], $chunk)),
        );
    }
});</code></pre></div>

<?php if (null !== $entity) { ?>
<?php if ([] === $bypassed) { ?>
<p><?= t('DBAL bypasses the ORM: %s maps no lifecycle callback, entity listener or generated identifier, but check the Doctrine event subscribers before switching.', $e($entity)) ?></p>
<?php } else { ?>
<p><?= t('DBAL bypasses what %s relies on: %s. Move that logic into the import first.', $e($entity), $e(implode(', ', array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $bypassed)))) ?></p>
<?php } ?>
<?php } ?>
<p><?= t('Below a few hundred rows, or when the import needs the persisted objects, keep the ORM and flush and clear in batches.') ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/batch-processing.html', 'Doctrine ORM: Batch processing'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => t('Insert many rows with multi-row INSERT statements through DBAL instead of one INSERT per entity'),
];
