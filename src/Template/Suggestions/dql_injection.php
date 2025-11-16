<?php

declare(strict_types=1);

/**
 * Template for DQL Injection security suggestions.
 * Context variables:
 * @var string       $query - The vulnerable query
 * @var array<mixed> $vulnerable_parameters - List of vulnerable parameters
 * @var string       $risk_level - Risk level (high, critical, etc.)
 */
['query' => $query, 'vulnerable_parameters' => $vulnerableParams, 'risk_level' => $riskLevel] = $context;
$e                                                                                            = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>

<div class="suggestion-header">
    <h4>🔒 DQL Injection Vulnerability Detected</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        ⛔ <strong>CRITICAL SECURITY ISSUE: DQL Injection</strong><br>
        Risk Level: <strong><?php echo strtoupper($e($riskLevel)); ?></strong><br>
        Vulnerable parameters: <code><?php echo implode(', ', array_map($e, $vulnerableParams)); ?></code>
    </div>

    <h4>Vulnerable Code</h4>
    <div class="query-item">
        <pre><code class="language-php">// DANGEROUS: User input directly in query
$query = $em->createQuery("
    SELECT u FROM User u WHERE u.name = '" . $username . "'
");
// Attacker can inject: ' OR '1'='1</code></pre>
    </div>

    <h4> Secure Solution: Use Parameters</h4>
    <div class="query-item">
        <pre><code class="language-php">//  SAFE: Parameterized query
$query = $em->createQuery("
    SELECT u FROM User u WHERE u.name = :username
");
$query->setParameter('username', $username);
$result = $query->getResult();</code></pre>
    </div>

    <h4>Security Best Practices</h4>
    <ul>
        <li> <strong>Always</strong> use parameter binding with <code>setParameter()</code></li>
        <li> Never concatenate user input into queries</li>
        <li> Validate and sanitize all user inputs</li>
        <li> Use QueryBuilder for complex queries</li>
        <li>Never trust user input, even from authenticated users</li>
    </ul>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#dql-query-parameters" target="_blank" class="doc-link">
            📖 Doctrine DQL Parameters Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'Fix DQL injection vulnerability by using parameter binding'];
