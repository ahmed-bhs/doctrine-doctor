<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $className
 * @var mixed $methodName
 * @var string $vulnType
 * @var array<string, mixed> $context
 */
$className = (string) ($context['class_name'] ?? 'Repository');
$methodName = (string) ($context['method_name'] ?? 'findByUnsafeInput');
$vulnType = (string) ($context['vulnerability_type'] ?? 'SQL injection');
// Show the fix in the layer where the concatenation was found: an ORM QueryBuilder or raw DBAL SQL.
$isDbal = 'dbal' === ($context['layer'] ?? 'orm');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>

<?php echo suggestionHeader('SQL Injection vulnerability'); ?>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <?php echo $e($className); ?>::<?php echo $e($methodName); ?>() - <?php echo $e($vulnType); ?> vulnerability
    </div>

    <p>String concatenation in SQL queries allows query manipulation.</p>

<?php if ($isDbal) { ?>
    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">// Vulnerable
$sql = 'SELECT * FROM users WHERE id = ' . $userId;
$conn-&gt;executeQuery($sql);</code></pre>
    </div>

    <h4>Fix: bind the value</h4>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\DBAL\ParameterType;

// Safe: the value travels separately from the SQL
$result = $conn-&gt;executeQuery(
    'SELECT * FROM users WHERE id = ?',
    [$userId],
    [ParameterType::INTEGER],
);</code></pre>
    </div>
<?php } else { ?>
    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">// Vulnerable
$qb-&gt;select('u')
   -&gt;from(User::class, 'u')
   -&gt;where('u.email = \'' . $email . '\'');</code></pre>
    </div>

    <h4>Fix: bind the value</h4>
    <div class="query-item">
        <pre><code class="language-php">// Safe: the value travels separately from the DQL
$qb-&gt;select('u')
   -&gt;from(User::class, 'u')
   -&gt;where('u.email = :email')
   -&gt;setParameter('email', $email);</code></pre>
    </div>
<?php } ?>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/security.html', 'Doctrine ORM Security'); ?>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'SQL injection risk - use prepared statements'];
