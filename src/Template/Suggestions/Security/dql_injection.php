<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $query
 * @var mixed $vulnerableParams
 * @var string $riskLevel
 * @var array<string, mixed> $context
 */
$query = (string) ($context['query'] ?? 'SELECT u FROM User u WHERE u.name = :username');
$vulnerableParams = $context['vulnerable_parameters'] ?? ['username'];
$riskLevel = (string) ($context['risk_level'] ?? 'high');
if (!is_array($vulnerableParams) || [] === $vulnerableParams) {
    $vulnerableParams = ['username'];
}
$vulnerableParams = array_values(array_map(static fn (mixed $param): string => (string) $param, $vulnerableParams));
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>

<?php echo suggestionHeader('DQL Injection vulnerability'); ?>

<div class="suggestion-content">
    <div class="alert alert-danger">
        Risk: <?php echo $e($riskLevel); ?> - Vulnerable parameters: <code><?php echo implode(', ', array_map(static fn (mixed $param): string => $e((string) $param), $vulnerableParams)); ?></code>
    </div>

    <p>String concatenation in DQL queries allows query manipulation.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">// Vulnerable
$query = $em->createQuery("
    SELECT u FROM User u WHERE u.name = '" . $username . "'
");
// Attacker input: ' OR '1'='1</code></pre>
    </div>

    <h4>Fix with parameters</h4>
    <div class="query-item">
        <pre><code class="language-php">// Safe
$query = $em->createQuery("
    SELECT u FROM User u WHERE u.name = :username
");
$query->setParameter('username', $username);
$result = $query->getResult();</code></pre>
    </div>

    <p>Use <code>setParameter()</code> instead of concatenation.</p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#dql-query-parameters', 'Doctrine DQL parameters'); ?>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'DQL injection risk - use parameter binding'];
