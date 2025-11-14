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
    <h4>SQL Injection vulnerability</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Security issue in <?php echo $e($className); ?>::<?php echo $e($methodName); ?>()</strong><br>
        Found a <?php echo $e($vulnType); ?> vulnerability
    </div>

    <p>Concatenating user input directly into SQL queries opens the door to SQL injection attacks. An attacker could manipulate the query to access or modify data they shouldn't have access to.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">// Vulnerable to injection
$sql = "SELECT * FROM users WHERE id = " . $userId;
$conn->executeQuery($sql);</code></pre>
    </div>

    <h4>Safe alternative</h4>
    <div class="query-item">
        <pre><code class="language-php">// Using prepared statements
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bindValue(1, $userId, \PDO::PARAM_INT);
$result = $stmt->executeQuery();</code></pre>
    </div>

    <p>Prepared statements ensure that user input is always treated as data, never as executable SQL. If you can, stick to Doctrine's QueryBuilder or DQL instead of writing raw SQL.</p>
</div>

<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'SQL injection risk - use prepared statements'];
