<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $connectionCollation
 * @var string $columns
 * @var string $fixCommand
 * @var array<string, mixed> $context
 */
$connectionCollation = (string) ($context['connection_collation'] ?? 'unknown');
$columns             = (string) ($context['columns'] ?? '');
$fixCommand          = (string) ($context['fix_command'] ?? '');
$e                   = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$eCode               = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_NOQUOTES, 'UTF-8');
ob_start();
?>
<div class="suggestion-header"><h4>View collation incompatible with the connection</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><strong>These queries fail with error 1267</strong><br>A <code>CREATE VIEW</code> statement freezes the session collation into the literals of its definition. Comparing such a column against a literal makes both operands carry the same coercibility, so MySQL has no rule to arbitrate between them and rejects the query outright.</div>
<?php if ($columns) { ?>
<h4>Affected columns</h4>
<div class="query-item"><pre><code><?php echo $eCode($columns); ?></code></pre></div>
<?php } ?>
<h4>Connection collation</h4>
<table><tr><th>Application connects with</th><td><code><?php echo $e($connectionCollation); ?></code></td></tr></table>
<h4>Two ways to fix</h4>
<p><strong>1. Recreate the view</strong> from a connection using the application collation. Simple, but the mismatch returns if the view is ever recreated from a differently configured client — a SQL console, a migration run outside the application, or a <code>mysqldump</code> restore, which reissues each view wrapped in the <code>SET collation_connection</code> in force when it was first created.</p>
<p><strong>2. Pin the literals</strong> with an explicit <code>COLLATE</code>. An explicit collate carries coercibility 0, which outranks any literal, so the view no longer depends on the session that creates it. This is the durable fix.</p>
<?php if ($fixCommand) { ?>
<div class="query-item"><pre><code class="language-sql"><?php echo $eCode($fixCommand); ?></code></pre></div>
<?php } ?>
<h4>Prevention</h4>
<p>Pin the collation on the connection itself so every view created through the application inherits it. With Doctrine DBAL, pass it in the connection options:</p>
<div class="query-item"><pre><code class="language-php"><?php echo $eCode("// config/packages/doctrine.yaml equivalent, in PHP:\n'driverOptions' => [\n    PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'\",\n],\n'defaultTableOptions' => [\n    'charset' => 'utf8mb4',\n    'collate' => 'utf8mb4_unicode_ci',\n],"); ?></code></pre></div>

<?php echo suggestionDocLink('https://dev.mysql.com/doc/refman/8.4/en/charset-collation-coercibility.html', 'MySQL: Collation Coercibility in Expressions'); ?>
<?php echo suggestionDocLink('https://dev.mysql.com/doc/refman/8.4/en/charset-collate.html', 'MySQL: The COLLATE Clause'); ?>
<?php echo suggestionDocLink('https://mariadb.com/kb/en/create-view/', 'MariaDB: CREATE VIEW'); ?>
<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/configuration.html', 'Doctrine DBAL Connection Configuration'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => sprintf(
        'View columns collate differently from the connection (%s), which raises error 1267 on comparison',
        $connectionCollation,
    ),
];
