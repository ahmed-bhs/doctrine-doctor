<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $className
 * @var mixed $methodName
 * @var mixed $vulnType
 * @var mixed $context
 */
['class_name' => $className, 'method_name' => $methodName, 'vulnerability_type' => $vulnType] = $context;
$e                                                                                            = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>

<div class="suggestion-header">
    <h4>SQL Injection vulnerability</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <?php echo $e($className); ?>::<?php echo $e($methodName); ?>() - <?php echo $e($vulnType); ?> vulnerability
    </div>

    <p>String concatenation in SQL queries allows query manipulation.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">// Vulnerable
$sql = "SELECT * FROM users WHERE id = " . $userId;
$conn->executeQuery($sql);</code></pre>
    </div>

    <h4>Fix with prepared statements</h4>
    <div class="query-item">
        <pre><code class="language-php">// Safe
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bindValue(1, $userId, \PDO::PARAM_INT);
$result = $stmt->executeQuery();</code></pre>
    </div>

    <p>Use prepared statements or prefer QueryBuilder/DQL over raw SQL.</p>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'SQL injection risk - use prepared statements'];
