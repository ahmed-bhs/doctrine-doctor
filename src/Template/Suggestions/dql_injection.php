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
    <h4>DQL Injection vulnerability</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Security issue</strong> - Risk: <?php echo $e($riskLevel); ?><br>
        Vulnerable parameters: <code><?php echo implode(', ', array_map($e, $vulnerableParams)); ?></code>
    </div>

    <p>Concatenating user input directly into DQL queries allows attackers to manipulate the query logic and access unauthorized data.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">// Vulnerable to injection
$query = $em->createQuery("
    SELECT u FROM User u WHERE u.name = '" . $username . "'
");
// Attacker can inject: ' OR '1'='1</code></pre>
    </div>

    <h4>Use parameters</h4>
    <div class="query-item">
        <pre><code class="language-php">// Safe with parameters
$query = $em->createQuery("
    SELECT u FROM User u WHERE u.name = :username
");
$query->setParameter('username', $username);
$result = $query->getResult();</code></pre>
    </div>

    <p>Always use parameter binding with <code>setParameter()</code>. Never concatenate user input into queries, even from authenticated users.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#dql-query-parameters" target="_blank" class="doc-link">
            📖 Doctrine DQL parameters docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'DQL injection risk - use parameter binding'];
