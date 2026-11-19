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
<?php echo suggestionHeader('Bind values instead of concatenating them'); ?>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <?php echo t('%s::%s() builds a query by string concatenation (%s). Input containing quotes can change what the query does.', $e($className), $e($methodName), $e($vulnType)); ?>
    </div>

<?php if ($isDbal) { ?>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\DBAL\ParameterType;

// Before
$conn-&gt;executeQuery('SELECT * FROM users WHERE id = ' . $userId);

// After: the value travels separately from the SQL
$result = $conn-&gt;executeQuery(
    'SELECT * FROM users WHERE id = ?',
    [$userId],
    [ParameterType::INTEGER],
);</code></pre>
    </div>
<?php } else { ?>
    <div class="query-item">
        <pre><code class="language-php">// Before
$qb-&gt;where('u.email = \'' . $email . '\'');

// After: the value travels separately from the DQL
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

return ['code' => $code, 'description' => t('Bind query values as parameters instead of concatenating them')];
