<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$column = $context['column'] ?? '';
$literal = $context['literal'] ?? '';
$originalQuery = $context['original_query'] ?? '';
$isParameter = 'string_column_vs_integer_parameter' === ($context['kind'] ?? '');
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<?php echo suggestionHeader('Text column compared to a number'); ?>

<div class="suggestion-content">
    <div class="alert alert-warning">
<?php if ($isParameter) { ?>
        <code><?= $e((string) $column) ?></code> is a text column compared to <?= $e((string) $literal) ?>, bound as an integer:
        DQL infers the binding type from the PHP value, so an <code>int</code> is sent as a number.
<?php } else { ?>
        <code><?= $e((string) $column) ?></code> is a text column compared to the number <code><?= $e((string) $literal) ?></code>.
<?php } ?>
        MySQL and MariaDB convert the column value of every row to a number before comparing,
        so the index on the column cannot be used. PostgreSQL rejects the comparison.
    </div>

    <h4>Original query</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= $e((string) $originalQuery) ?></code></pre>
    </div>

    <h4>Fix: compare to a string</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: the number forces a conversion of every row
$qb-&gt;andWhere('p.code = 123');

// After: a string literal keeps the index usable
$qb-&gt;andWhere("p.code = '123'");

// With a parameter, pass a string or declare the type
$qb-&gt;andWhere('p.code = :code')
   -&gt;setParameter('code', (string) $code, \Doctrine\DBAL\Types\Types::STRING);</code></pre>
    </div>

    <h4>Why it matters</h4>
    <p>A numeric column compared to a quoted number (<code>user_id = '42'</code>) is harmless: the literal is converted once
    and the index is used. The reverse is not, because the conversion applies to the column.</p>

    <?php echo suggestionDocLink('https://use-the-index-luke.com/sql/where-clause/obfuscation/numeric-strings', 'Use The Index, Luke! Numeric strings'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Compare text columns to strings so the database can use their index',
];
