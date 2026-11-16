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
<?php echo suggestionHeader('Pin the collation of the view literals'); ?>
<div class="suggestion-content">
<p>The view froze a session collation that differs from the connection's <code><?php echo $e($connectionCollation); ?></code><?php if ($columns) { ?> (columns: <code><?php echo $eCode($columns); ?></code>)<?php } ?>. Comparing these columns with a literal then fails with MySQL error 1267 (illegal mix of collations).</p>
<?php if ($fixCommand) { ?>
<div class="query-item"><pre><code class="language-sql"><?php echo $eCode($fixCommand); ?></code></pre></div>
<?php } else { ?>
<div class="query-item"><pre><code class="language-sql">CREATE OR REPLACE VIEW active_user AS
SELECT u.* FROM user u
WHERE u.status = 'active' COLLATE utf8mb4_unicode_ci;</code></pre></div>
<?php } ?>
<p>An explicit <code>COLLATE</code> keeps the view correct whichever client recreates it; recreating the view from the application connection also works, until the next restore from another client.</p>

<?php echo suggestionDocLink('https://dev.mysql.com/doc/refman/8.4/en/charset-collation-coercibility.html', 'MySQL: Collation Coercibility in Expressions'); ?>
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
