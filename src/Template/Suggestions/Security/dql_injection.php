<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $query
 * @var mixed $vulnerableParams
 * @var mixed $riskLevel
 * @var mixed $context
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
        Risk: <?php echo $e($riskLevel); ?> - Vulnerable parameters: <code><?php echo implode(', ', array_map($e, $vulnerableParams)); ?></code>
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

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#dql-query-parameters" target="_blank" class="doc-link">
            📖 Doctrine DQL parameters
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'DQL injection risk - use parameter binding'];
