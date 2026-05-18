<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$column = $context['column'] ?? '';
$literal = $context['literal'] ?? '';
$kind = $context['kind'] ?? '';
$originalQuery = $context['original_query'] ?? '';
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

$isNumericVsString = 'numeric_column_vs_string_literal' === $kind;

ob_start();
?>

<div class="suggestion-header">
    <h4>Implicit type conversion in WHERE</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Comparing <code><?= $e((string) $column) ?></code> to <code><?= $e((string) $literal) ?></code> mixes incompatible types.
        The database must convert one side before evaluating the predicate, which disables index usage on the column.
    </div>

    <h4>Original query</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= $e((string) $originalQuery) ?></code></pre>
    </div>

<?php if ($isNumericVsString): ?>
    <h4>Fix: bind with the correct PHP type</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: integer value passed as string disables the index
$qb-&gt;andWhere('u.id = :id')
   -&gt;setParameter('id', (string) $id); // wrong type

// After: pass the value as int -> Doctrine binds as PDO::PARAM_INT
$qb-&gt;andWhere('u.id = :id')
   -&gt;setParameter('id', (int) $id);

// Or be explicit about the binding type
$qb-&gt;setParameter('id', $id, \PDO::PARAM_INT);</code></pre>
    </div>

    <h4>Why it matters</h4>
    <p>Most engines (MySQL, MariaDB) silently coerce one side and lose the ability to use the index on the column.
    PostgreSQL is stricter and may refuse the comparison outright. Either way, the cost is a full table scan or worse.</p>
<?php else: ?>
    <h4>Fix: pass a typed date, not an integer</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: timestamp as integer
$qb-&gt;andWhere('o.createdAt &gt;= :since')
   -&gt;setParameter('since', 1700000000);

// After: pass a DateTimeImmutable, Doctrine binds as datetime
$qb-&gt;andWhere('o.createdAt &gt;= :since')
   -&gt;setParameter('since', new \DateTimeImmutable('@1700000000'));

// Or pass an ISO 8601 string with an explicit Types::DATETIME_IMMUTABLE binding
$qb-&gt;setParameter('since', $date, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);</code></pre>
    </div>
<?php endif; ?>

    <p><a href="https://use-the-index-luke.com/sql/where-clause/obfuscation/numeric-strings" target="_blank" rel="noopener noreferrer" class="doc-link">Use The Index, Luke! Numeric strings</a></p>
</div>

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Bind parameters with the correct PHP/SQL type so the database can use the column index',
];
