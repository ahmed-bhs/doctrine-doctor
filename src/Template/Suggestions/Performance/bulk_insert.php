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
        <?= $e((string) $count) ?> single-row INSERT statements were sent to <code><?= $e($table) ?></code>, one round trip each.
        The ORM writes one statement per persisted entity; a multi-row INSERT writes hundreds of rows in one.
    </div>

    <h4>Repeated statement</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= $e($sql) ?></code></pre>
    </div>

    <h4>Fix: multi-row INSERT through DBAL, in chunks</h4>
    <div class="query-item">
        <pre><code class="language-php">$connection = $entityManager-&gt;getConnection();

$connection-&gt;transactional(function () use ($connection, $rows): void {
    foreach (array_chunk($rows, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?)'));

        $connection-&gt;executeStatement(
            'INSERT INTO <?= $e($table) ?> (name, email) VALUES ' . $placeholders,
            array_merge(...array_map(fn (array $row): array =&gt; [$row['name'], $row['email']], $chunk)),
        );
    }
});</code></pre>
    </div>

<?php if (null !== $entity) { ?>
    <h4>What DBAL bypasses for <?= $e($entity) ?></h4>
<?php if ([] === $bypassed) { ?>
    <p>No lifecycle callback, entity listener or generated identifier is mapped on this entity.
    Doctrine event subscribers registered on the connection or entity manager are skipped too: check them before switching.</p>
<?php } else { ?>
    <ul>
<?php foreach ($bypassed as $item) { ?>
        <li><?= $e(is_string($item) ? $item : '') ?></li>
<?php } ?>
    </ul>
    <p>Move that logic into the import, or keep the ORM and flush and clear in batches instead.</p>
<?php } ?>
<?php } ?>

    <p>Keep the ORM when the import needs the persisted objects afterwards, or when the rows are few:
    below a few hundred rows, flushing and clearing in batches is usually enough.</p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/batch-processing.html', 'Doctrine ORM: Batch processing'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Insert many rows with multi-row INSERT statements through DBAL instead of one INSERT per entity',
];
