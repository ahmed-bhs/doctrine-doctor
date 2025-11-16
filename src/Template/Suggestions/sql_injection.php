<?php

declare(strict_types=1);

/**
 * Template for SQL Injection security suggestions.
 * Context variables:
 */
['class_name' => $className, 'method_name' => $methodName, 'vulnerability_type' => $vulnType] = $context;
$e                                                                                            = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>

<div class="suggestion-header">
    <h4>🔒 SQL Injection Risk</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        ⛔ <strong>CRITICAL: SQL Injection in <?php echo $e($className); ?>::<?php echo $e($methodName); ?>()</strong><br>
        Type: <?php echo $e($vulnType); ?>
    </div>

    <h4>Vulnerable Pattern</h4>
    <div class="query-item">
        <pre><code class="language-php">// DANGEROUS
$sql = "SELECT * FROM users WHERE id = " . $userId;
$conn->executeQuery($sql);</code></pre>
    </div>

    <h4> Secure Solution</h4>
    <div class="query-item">
        <pre><code class="language-php">//  SAFE: Use prepared statements
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bindValue(1, $userId, \PDO::PARAM_INT);
$result = $stmt->executeQuery();</code></pre>
    </div>

    <ul>
        <li>Use prepared statements with <code>prepare()</code> and <code>bindValue()</code></li>
        <li>Never concatenate user input into SQL</li>
        <li>Prefer Doctrine ORM/Query Builder over raw SQL</li>
    </ul>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'Fix SQL injection by using prepared statements'];
